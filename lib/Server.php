<?php

namespace Aerys;

use Amp\Reactor;
use Amp\Success;
use Amp\Future;

class Server {
    const NAME = 'Aerys/0.1.0-dev';
    const VERSION = '0.1.0-dev';

    const STOPPED = 0;
    const BOUND = 1;
    const STARTING = 2;
    const STARTED = 3;
    const PAUSED = 4;
    const STOPPING = 5;

    const OP_DEBUG = -1;
    const OP_MAX_CONNECTIONS = 1;
    const OP_MAX_REQUESTS = 2;
    const OP_KEEP_ALIVE_TIMEOUT = 3;
    const OP_DISABLE_KEEP_ALIVE = 4;
    const OP_MAX_HEADER_BYTES = 5;
    const OP_MAX_BODY_BYTES = 6;
    const OP_DEFAULT_CONTENT_TYPE = 7;
    const OP_DEFAULT_TEXT_CHARSET = 8;
    const OP_SEND_SERVER_TOKEN = 10;
    const OP_NORMALIZE_METHOD_CASE = 11;
    const OP_REQUIRE_BODY_LENGTH = 12;
    const OP_SOCKET_SO_LINGER_ZERO = 13;
    const OP_SOCKET_BACKLOG_SIZE = 14;
    const OP_ALLOWED_METHODS = 15;
    const OP_DEFAULT_HOST = 16;

    private $state = self::STOPPED;
    private $reactor;
    private $responderFactory;
    private $observers;

    private $vhosts;
    private $boundSockets = [];
    private $acceptWatchers = [];
    private $pendingTlsWatchers = [];
    private $clients = [];
    private $requestIdClientMap = [];
    private $exportedSocketIdMap = [];
    private $cachedClientCount = 0;
    private $lastRequestId = 0;

    private $now;
    private $httpDateNow;
    private $httpDateFormat = 'D, d M Y H:i:s';
    private $keepAliveWatcher;
    private $keepAliveTimeouts = [];

    private $debug = false;
    private $maxConnections = 1500;
    private $maxRequests = 150;
    private $keepAliveTimeout = 10;
    private $defaultHost;
    private $defaultContentType = 'text/html';
    private $defaultTextCharset = 'utf-8';
    private $sendServerToken = FALSE;
    private $disableKeepAlive = FALSE;
    private $socketSoLingerZero = FALSE;
    private $socketBacklogSize = 128;
    private $normalizeMethodCase = TRUE;
    private $requireBodyLength = TRUE;
    private $maxHeaderBytes = 8192;
    private $maxBodyBytes = 2097152;
    private $allowedMethods;
    private $readGranularity = 262144; // @TODO Add option setter
    private $hasSocketsExtension;

    private $stopPromisor;

    public function __construct(Reactor $reactor = null, AsgiResponderFactory $rf = null) {
        $this->reactor = $reactor ?: \Amp\getReactor();
        $this->responderFactory = $rf ?: new AsgiResponderFactory;
        $this->observers = new \SplObjectStorage;
        $this->hasSocketsExtension = extension_loaded('sockets');
        $this->allowedMethods = [
            'GET' => 1,
            'HEAD' => 1,
            'OPTIONS' => 1,
            'TRACE' => 1,
            'PUT' => 1,
            'POST' => 1,
            'PATCH' => 1,
            'DELETE' => 1,
        ];
    }

    /**
     * Retrieve the current server state
     *
     * @return int
     */
    public function getState() {
        return $this->state;
    }

    /**
     * Attach a server event observer
     *
     * @param ServerObserver $observer
     * @return void
     */
    public function attachObserver(ServerObserver $observer) {
        $this->observers->attach($observer);
    }

    /**
     * Detach a server event observer
     *
     * @param ServerObserver $observer
     * @return void
     */
    public function detachObserver(ServerObserver $observer) {
        $this->observers->detach($observer);
    }

    /**
     * Log an error
     *
     * @param string $error
     * @return void
     * @TODO Allow custom logger injection. For now we're just going to write to STDERR
     */
    public function log($error) {
        fwrite(STDERR, "{$error}\n");
    }

    /**
     * Return socket control from a Responder back to the Server upon response completion
     *
     * NOTE: Theoretically this function could take a $clientId instead of a $requestId because
     * we're simply ensuring pipelined response for HTTP/1.1 clients are sent in the correct
     * order. However, we're using $requestId because HTTP/2 will allow multiplexing on the same
     * TCP connection. Using the $requestId now will allow us to use the same code regardless of
     * the protocol in use once HTTP/2 support is implemented.
     *
     * @param int $requestId
     * @param bool $mustClose
     * @throws \DomainException On unknown control code
     * @return void
     */
    public function resumeSocketControl($requestId, $mustClose) {
        if (empty($this->requestIdClientMap[$requestId])) {
            return;
        }

        $client = $this->requestIdClientMap[$requestId];

        if ($mustClose) {
            $this->closeClient($client);
            return;
        }

        unset(
            $this->requestIdClientMap[$requestId],
            $client->pipeline[$requestId],
            $client->cycles[$requestId]
        );

        $client->pendingResponder = null;
        $this->reactor->disable($client->writeWatcher);

        $nextRequestId = key($client->cycles);
        if (isset($client->pipeline[$nextRequestId])) {
            $this->cedeResponderSocketControl($client, $nextRequestId);
        } elseif (empty($client->pipeline)) {
            $this->renewKeepAliveTimeout($client->id);
        }
    }

    /**
     * Export the specified socket to the calling code
     *
     * Exported sockets continue to count against the server's maxConnections limit to protect
     * against resource overflow. Applications MUST invoke the returned callback in order to free
     * the occupied connection slot.
     *
     * Applications that export sockets do not need to close the socket manually themselves -- the
     * socket will be closed subject to the server's settings once the discharge callback is
     * invoked.
     *
     * @param resource $socket
     * @throws \DomainException On unknown socket
     * @return Closure A callback to discharge any remaining server references to the exported socket
     */
    public function exportSocket($socket) {
        $socketId = (int) $socket;
        if (empty($this->clients[$socketId])) {
            throw new \DomainException(
                sprintf('Cannot export unknown socket: %s', $socket)
            );
        }

        $client = $this->clients[$socketId];
        $socket = $client->socket;
        $this->exportedSocketIdMap[$socketId] = $socket;
        $this->clearClientReferences($client);

        // We just decremented the client count when clearing references to the client. Let's
        // re-increment it back up so we can keep track of resource usage until the exported
        // client is explicitly discharged by the application code that exported it.
        $this->cachedClientCount++;

        return function() use ($socketId) {
            if (isset($this->exportedSocketIdMap[$socketId])) {
                $socket = $this->exportedSocketIdMap[$socketId];
                $this->cachedClientCount--;
                $this->doSocketClose($socket);
                unset($this->exportedSocketIdMap[$socketId]);
            }
        };
    }

    /**
     * Bind sockets from the specified virtual host definitions (but don't listen yet)
     *
     * @param Vhost|VhostGroup|array $vhosts
     * @throws \LogicException If server sockets already bound
     * @throws \RuntimeException On socket bind failure
     * @return void
     */
    public function bind($vhosts) {
        if ($this->state !== self::STOPPED) {
            throw new \LogicException(
                'Server sockets already bound; please stop the server before calling bind()'
            );
        }

        $this->vhosts = $this->normalizeVhosts($vhosts);

        $addresses = array_unique($this->vhosts->getBindableAddresses());
        $tlsBindings = $this->vhosts->getTlsBindingsByAddress();
        foreach ($addresses as $address) {
            $context = stream_context_create(['socket' => [
                'backlog' => $this->socketBacklogSize,
                'so_reuseport' => true,
            ]]);
            if (isset($tlsBindings[$address])) {
                stream_context_set_option($context, ['ssl' => $tlsBindings[$address]]);
            }

            $this->boundSockets[$address] = $this->bindSocket($address, $context);
        }

        $this->state = self::BOUND;
    }

    private function normalizeVhosts($vhostOrGroup) {
        if ($vhostOrGroup instanceof VhostGroup) {
            $vhosts = $vhostOrGroup;
        } elseif ($vhostOrGroup instanceof Vhost) {
            $vhosts = new VhostGroup;
            $vhosts->addHost($vhostOrGroup);
        } elseif ($vhostOrGroup && is_array($vhostOrGroup)) {
            $vhosts = new VhostGroup;
            foreach ($vhostOrGroup as $vhost) {
                $vhosts->addHost($vhost);
            }
        } else {
            throw new \DomainException(
                'Invalid host definition; Vhost, VhostGroup or array of Vhost instances required'
            );
        }

        return $vhosts;
    }

    private function bindSocket($address, $context) {
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        if (!$socket = @stream_socket_server($address, $errno, $errstr, $flags, $context)) {
            throw new \RuntimeException(
                sprintf('Failed binding socket on %s: [Err# %s] %s', $address, $errno, $errstr)
            );
        }

        return $socket;
    }

    public function getBindableAddresses() {
        return $this->vhosts ? $this->vhosts->getBindableAddresses() : [];
    }

    /**
     * Initiate listening on bound sockets
     *
     * Technically the bound sockets are already listening as far as the OS is concerned. However,
     * our server will not accept any new connections until the promise resulting from
     * Server::listen() resolves so that startup observers have a chance to complete boot tasks.
     *
     * @throws \LogicException
     * @return \Amp\Promise
     */
    public function listen() {
        if ($this->state === self::STOPPED) {
            throw new \LogicException(
                'No host sockets bound; please bind() prior to listen()ing.'
            );
        } elseif ($this->state !== self::BOUND) {
            throw new \LogicException(
                'Server sockets already bound and listening.'
            );
        }

        foreach ($this->boundSockets as $address => $socket) {
            $context = stream_context_get_options($socket);
            // Don't enable these watchers now -- wait until start observers report completion
            $acceptWatcher = $this->reactor->onReadable($socket, [$this, 'accept'], $enableNow = false);
            $this->acceptWatchers[$address] = $acceptWatcher;
        }

        $this->state = self::STARTING;

        return $this->notifyObservers()->when(function($e, $r) {
            if (empty($e)) {
                foreach ($this->acceptWatchers as $acceptWatcher) {
                    $this->reactor->enable($acceptWatcher);
                }
                $this->renewHttpDate();
                $this->keepAliveWatcher = $this->reactor->repeat(function() {
                    $this->timeoutKeepAlives();
                }, $msInterval = 1000);

                $this->state = self::STARTED;
            } else {
                $this->log($e);
                $this->stop();
            }
        });
    }

    private function notifyObservers() {
        $promises = [];
        foreach ($this->observers as $observer) {
            $promises[] = $observer->onServerUpdate($this);
        }

        return \Amp\all($promises);
    }

    /**
     * Stop the server (gracefully)
     *
     * The server will take as long as necessary to complete the currently outstanding requests.
     * New client acceptance is suspended, previously assigned responses are sent in full and any
     * currently unfulfilled requests receive a 503 Service Unavailable response.
     *
     * @return \Amp\Promise
     */
    public function stop() {
        if ($this->state === self::STOPPED) {
            return new Success;
        }
        if ($this->stopPromisor) {
            return $this->stopPromisor;
        }

        foreach ($this->acceptWatchers as $watcherId) {
            $this->reactor->cancel($watcherId);
        }

        foreach ($this->pendingTlsWatchers as $client) {
            $this->failTlsConnection($client);
        }

        foreach ($this->clients as $client) {
            @stream_socket_shutdown($client->socket, STREAM_SHUT_RD);
        }

        $this->state = self::STOPPING;
        $observerPromise = $this->notifyObservers();

        // If no clients are connected we need only resolve the observer promise
        if (empty($this->clients)) {
            return ($this->stopPromisor = $observerPromise);
        }

        $this->stopPromisor = new Future;

        foreach ($this->clients as $client) {
            $this->stopClient($client);
        }

        $returnPromise = \Amp\all([$this->stopPromisor->promise(), $observerPromise]);
        $returnPromise->when(function($e, $r) {
            $this->stopPromisor = null;
            $this->reactor->cancel($this->keepAliveWatcher);
            $this->acceptWatchers = [];
            $this->boundSockets = [];
            $this->state = self::STOPPED;

            if ($e) {
                $this->log($e);
            }
        });

        return $returnPromise;
    }

    private function stopClient($client) {
        if (empty($client->cycles)) {
            $this->closeClient($client);
            return;
        }

        $unassignedRequestIds = array_keys(array_diff_key($client->cycles, $client->pipeline));
        foreach ($unassignedRequestIds as $requestId) {
            $responder = $this->responderFactory->make([
                'status' => HTTP_STATUS["SERVICE_UNAVAILABLE"],
                'header' => ['Connection: close'],
                'body'   => '<html><body><h1>503 Service Unavailable</h1></body></html>'
            ]);
            $requestCycle = $client->cycles[$requestId];
            $requestCycle->responder = $responder;
        }
        $this->hydrateClientResponderPipeline($client);

        if (empty($client->pendingResponder) &&
            ($nextRequestId = key($client->cycles)) &&
            isset($client->pipeline[$nextRequestId])
        ) {
            $this->cedeResponderSocketControl($client, $nextRequestId);
        }
    }

    private function pauseClientAcceptance() {
        foreach ($this->acceptWatchers as $watcherId) {
            $this->reactor->disable($watcherId);
        }
        if ($this->state !== self::STOPPING) {
            $this->state = self::PAUSED;
        }
    }

    private function resumeClientAcceptance() {
        foreach ($this->acceptWatchers as $watcherId) {
            $this->reactor->enable($watcherId);
        }
        if ($this->state !== self::STOPPING) {
            $this->state = self::STARTED;
        }
    }

    /**
     * Accept new client socket(s)
     *
     * This method is invoked by the event reactor when a server socket has clients waiting to
     * connect.
     *
     * @param \Amp\Reactor $reactor
     * @param int $watcherId
     * @param resource $server
     */
    public function accept($reactor, $watcherId, $server) {
        while ($client = @stream_socket_accept($server, $timeout = 0)) {
            stream_set_blocking($client, false);
            $this->cachedClientCount++;

            if (isset(stream_context_get_options($client)['ssl'])) {
                $socketId = (int) $client;
                $tlsWatcher = $this->reactor->onReadable($client, [$this, 'doTlsHandshake']);
                $this->pendingTlsWatchers[$socketId] = $tlsWatcher;
            } else {
                $this->onClient($client, $isEncrypted = false);
            }

            if ($this->maxConnections > 0 && $this->cachedClientCount >= $this->maxConnections) {
                $this->pauseClientAcceptance();
                break;
            }
        }
    }

    public function doTlsHandshake($reactor, $watcherId, $socket) {
        $handshakeStatus = @stream_socket_enable_crypto($socket, true);
        if ($handshakeStatus) {
            $this->clearPendingTlsClient($socket);
            $this->onClient($socket, $isEncrypted = true);
        } elseif ($handshakeStatus === false) {
            $this->failTlsConnection($socket);
        }
    }

    private function clearPendingTlsClient($socket) {
        $socketId = (int) $socket;
        $cryptoWatcher = $this->pendingTlsWatchers[$socketId];
        $this->reactor->cancel($cryptoWatcher);
        unset($this->pendingTlsWatchers[$socketId]);
    }

    private function failTlsConnection($socket) {
        $this->cachedClientCount--;
        $this->clearPendingTlsClient($socket);
        @fclose($socket);
        if ($this->state === self::PAUSED && $this->cachedClientCount <= $this->maxConnections) {
            $this->resumeClientAcceptance();
        }
    }

    private function timeoutKeepAlives() {
        $now = $this->renewHttpDate();
        foreach ($this->keepAliveTimeouts as $socketId => $expiryTime) {
            if ($expiryTime <= $now) {
                $client = $this->clients[$socketId];
                $this->closeClient($client);
            } else {
                break;
            }
        }
    }

    private function renewHttpDate() {
        // Date string generation is (relatively) expensive. Since we only need HTTP
        // dates at a granularity of one second we're better off to generate this
        // information once per second and cache it. Because we also timeout keep-alive
        // connections at one-second intervals we cache the unix timestamp for
        // comparisons against client activity times.
        $time = time();
        $this->now = $time;
        $this->httpDateNow = gmdate('D, d M Y H:i:s', $time) . ' UTC';

        return $time;
    }

    private function renewKeepAliveTimeout($socketId) {
        // *** IMPORTANT ***
        //
        // DO NOT remove the call to unset(); it looks superfluous but it's not.
        // Keep-alive timeout entries must be ordered by value. This means that
        // it's not enough to replace the existing map entry -- we have to remove
        // it completely and push it back onto the end of the array to maintain the
        // correct order.
        unset($this->keepAliveTimeouts[$socketId]);

        $this->keepAliveTimeouts[$socketId] = $this->now + $this->keepAliveTimeout;
    }

    private function onClient($socket, $isEncrypted) {
        stream_set_blocking($socket, FALSE);

        $socketId = (int) $socket;

        $client = new Client;
        $client->id = $socketId;
        $client->socket = $socket;
        $client->isEncrypted = $isEncrypted;

        $clientName = stream_socket_get_name($socket, TRUE);
        $serverName = stream_socket_get_name($socket, FALSE);
        list($client->clientAddress, $client->clientPort) = $this->parseSocketName($clientName);
        list($client->serverAddress, $client->serverPort) = $this->parseSocketName($serverName);

        $onParseEmit = [$this, 'onRequestParseEvent'];
        $client->requestParser = new RequestParser($onParseEmit, $options = [
            'maxBodySize' => $this->maxBodyBytes,
            'maxHeaderSize' => $this->maxHeaderBytes,
            'appData' => $client,
        ]);

        $onReadable = function() use ($client) { $this->readClientSocketData($client); };
        $client->readWatcher = $this->reactor->onReadable($socket, $onReadable);

        $onWritable = function() use ($client) {
            // @TODO: This conditional shouldn't be necessary. Figure out why
            // the write watcher is still active when it shouldn't be in the
            // UvSendfile responder.
            if ($client->pendingResponder) {
                $client->pendingResponder->write();
            } else {
                $this->reactor->disable($client->writeWatcher);
            }
        };
        $client->writeWatcher = $this->reactor->onWritable($socket, $onWritable, $enableNow = FALSE);

        $this->clients[$socketId] = $client;
    }

    private function parseSocketName($name) {
        // IMPORTANT: use strrpos() instead of strpos() or we'll break IPv6 addresses
        $portStartPos = strrpos($name, ':');
        $address = substr($name, 0, $portStartPos);
        $port = substr($name, $portStartPos + 1);

        return [$address, $port];
    }

    private function readClientSocketData(Client $client) {
        $data = @fread($client->socket, $this->readGranularity);
        if ($data != '') {
            $this->renewKeepAliveTimeout($client->id);
            $requestParser = $client->requestParser;
            $requestParser->parse($data);
        } elseif (!is_resource($client->socket) || @feof($client->socket)) {
            $this->closeClient($client);
        }
    }

    public function onRequestParseEvent(array $parseData, Client $client) {
        list($eventType, $parseResult, $errorStruct) = $parseData;
        switch ($eventType) {
            case RequestParser::HEADERS:
                $parseResult['headersOnly'] = true; // @TODO <-- kill this eventually
                $this->onPartialRequest($client, $parseResult);
                break;
            case RequestParser::BODY_PART:
                // @TODO This is only temporary to retain compat with the existing method of
                // accessing request entity bodies through a stream. It's going away sooner
                // rather than later.
                $client->body = $client->body ?: fopen("php://memory", "r+");
                fwrite($client->body, $parseResult['body']);
            case RequestParser::RESULT:
                if ($client->body) {
                    fwrite($client->body, $parseResult['body']);
                    rewind($client->body);
                    $parseResult['body'] = $client->body;
                }
                $parseResult['headersOnly'] = false; // @TODO <-- kill this eventually
                $this->onCompletedRequest($client, $parseResult);
                break;
            case RequestParser::ERROR:
                if ($client->partialCycle) {
                    $requestCycle = $client->partialCycle;
                    $client->partialCycle = null;
                } else {
                    list($requestCycle) = $this->initializeCycle($client, $parseResult);
                }

                list($errorCode, $errorMessage) = $errorStruct;
                $display = $this->debug ? "<pre>{$errorMessage}</pre>" : '<p>Malformed HTTP request</p>';
                $responder = $this->responderFactory->make([
                    'status' => $errorCode,
                    'header' => ['Connection: close'],
                    'body'   => sprintf("<html><body>%s</body></html>", $display)
                ]);

                $this->assignResponder($requestCycle, $responder);
            default:
                throw new \RuntimeException(
                    'Unexpected parser result code encountered'
                );
        }
    }

    /**
     * @TODO Invoke Vhost application partial responders here (not yet implemented). These
     * responders (if present) should be used to answer request Expect headers (or whatever people
     * wish to do before the body arrives).
     *
     * @TODO Support generator multitasking in partial responders
     */
    private function onPartialRequest(Client $client, array $parsedRequest) {
        list($requestCycle, $responder) = $this->initializeCycle($client, $parsedRequest);

        if ($requestCycle->expectsContinue && empty($responder)) {
            $responder = $this->responderFactory->make([
                'status' => HTTP_STATUS["CONTINUE"],
                'body' => ''
            ]);
        }

        // @TODO After responding to an expectation we probably need to modify the request parser's
        // state to avoid parse errors after a non-100 response. Otherwise we really have no choice
        // but to close the connection after this response.
        if ($responder) {
            $this->assignResponder($requestCycle, $responder);
        }
    }

    private function initializeCycle(Client $client, array $parseResult) {
        $__protocol = empty($parseResult["protocol"]) ? "1.0" : $parseResult["protocol"];
        $__method = empty($parseResult["method"]) ? "?" : $parseResult["method"];
        $__uri = empty($parseResult["uri"]) ? "?" : $parseResult["uri"];
        $__headers = empty($parseResult["headers"]) ? [] : $parseResult["headers"];
        $__body = $parseResult["body"];
        $__trace = $parseResult["trace"];
        $__headersOnly = $parseResult["headersOnly"];

        $__method = $this->normalizeMethodCase ? strtoupper($__method) : $__method;

        $requestId = ++$this->lastRequestId;
        $this->requestIdClientMap[$requestId] = $client;

        $requestCycle = new RequestCycle;
        $requestCycle->requestId = $requestId;
        $requestCycle->client = $client;
        $requestCycle->protocol = $__protocol;
        $requestCycle->method = $__method;
        $requestCycle->body = $__body;
        $requestCycle->headers = $__headers;
        $requestCycle->uri = $__uri;

        if (stripos($__uri, 'http://') === 0 || stripos($__uri, 'https://') === 0) {
            extract(parse_url($__uri), $flags = EXTR_PREFIX_ALL, $prefix = '__uri_');
            $requestCycle->hasAbsoluteUri = TRUE;
            $requestCycle->uriHost = $__uri_host;
            $requestCycle->uriPort = $__uri_port;
            $requestCycle->uriPath = $__uri_path;
            $requestCycle->uriQuery = $__uri_query;
        } elseif ($qPos = strpos($__uri, '?')) {
            $requestCycle->uriQuery = substr($__uri, $qPos + 1);
            $requestCycle->uriPath = substr($__uri, 0, $qPos);
        } else {
            $requestCycle->uriPath = $__uri;
        }

        if (empty($__headers['EXPECT'])) {
            $requestCycle->expectsContinue = FALSE;
        } elseif (stristr($__headers['EXPECT'][0], '100-continue')) {
            $requestCycle->expectsContinue = TRUE;
        } else {
            $requestCycle->expectsContinue = FALSE;
        }

        $client->requestCount++;
        $client->cycles[$requestCycle->requestId] = $requestCycle;
        $client->partialCycle = $__headersOnly ? $requestCycle : null;

        list($vhost, $isValidHost) = $this->vhosts->selectHost($requestCycle, $this->defaultHost);
        $requestCycle->vhost = $vhost;

        $serverName = $vhost->hasName() ? $vhost->getName() : $client->serverAddress;
        if ($serverName === '*') {
            $sp = $client->serverPort;
            $serverNamePort = ($sp == 80 || $sp == 443) ? '' : ":{$sp}";
            $serverName = $client->serverAddress . $serverNamePort;
        }

        $request = [
            'ASGI_VERSION'      => '0.1',
            'ASGI_NON_BLOCKING' => TRUE,
            'ASGI_ERROR'        => null,
            'ASGI_INPUT'        => $requestCycle->body,
            'SERVER_PORT'       => $client->serverPort,
            'SERVER_ADDR'       => $client->serverAddress,
            'SERVER_NAME'       => $serverName,
            'SERVER_PROTOCOL'   => $requestCycle->protocol,
            'REMOTE_ADDR'       => $client->clientAddress,
            'REMOTE_PORT'       => $client->clientPort,
            'HTTPS'             => $client->isEncrypted,
            'REQUEST_METHOD'    => $requestCycle->method,
            'REQUEST_URI'       => $requestCycle->uri,
            'REQUEST_URI_PATH'  => $requestCycle->uriPath,
            'QUERY_STRING'      => $requestCycle->uriQuery
        ];

        if (!empty($__headers['CONTENT-TYPE'])) {
            $request['CONTENT_TYPE'] = $__headers['CONTENT-TYPE'][0];
            unset($__headers['CONTENT-TYPE']);
        }

        if (!empty($__headers['CONTENT-LENGTH'])) {
            $request['CONTENT_LENGTH'] = $__headers['CONTENT-LENGTH'][0];
            unset($__headers['CONTENT-LENGTH']);
        }

        if ($requestCycle->uriQuery == "") {
            $request['QUERY'] = [];
        } else {
            parse_str($requestCycle->uriQuery, $request['QUERY']);
        }

        // @TODO Add cookie parsing
        //if (!empty($headers['COOKIE']) && ($cookies = $this->parseCookies($headers['COOKIE']))) {
        //    $request['COOKIE'] = $cookies;
        //}

        // @TODO Add multipart entity parsing

        foreach ($__headers as $field => $value) {
            $field = 'HTTP_' . str_replace('-', '_', $field);
            $value = isset($value[1]) ? implode(',', $value) : $value[0];
            $request[$field] = $value;
        }

        $requestCycle->request = $request;

        if (!$isValidHost) {
            $responder = $this->responderFactory->make([
                'status' => HTTP_STATUS["BAD_REQUEST"],
                'reason' => 'Bad Request: Invalid Host',
                'body'   => '<html><body><h1>400 Bad Request: Invalid Host</h1></body></html>',
            ]);
        } elseif (!isset($this->allowedMethods[$__method])) {
            $responder = $this->responderFactory->make([
                'status' => HTTP_STATUS["METHOD_NOT_ALLOWED"],
                'header' => [
                    'Connection: close',
                    'Allow: ' . implode(',', array_keys($this->allowedMethods)),
                ],
                'body'   => '<html><body><h1>405 Method Not Allowed</h1></body></html>',
            ]);
        } elseif ($__method === 'TRACE' && empty($requestCycle->headers['MAX_FORWARDS'])) {
            // @TODO Max-Forwards needs some additional server flag because that check shouldn't
            // be used unless the server is acting as a reverse proxy
            $responder = $this->responderFactory->make([
                'status' => HTTP_STATUS["OK"],
                'header' => ['Content-Type: message/http'],
                'body'   => $__trace,
            ]);
        } elseif ($__method === 'OPTIONS' && $requestCycle->uri === '*') {
            $responder = $this->responderFactory->make([
                'status' => HTTP_STATUS["OK"],
                'header' => ['Allow: ' . implode(',', array_keys($this->allowedMethods))],
            ]);
        } elseif ($this->requireBodyLength && $__headersOnly && empty($requestCycle->headers['CONTENT-LENGTH'])) {
            $responder = $this->responderFactory->make([
                'status' => HTTP_STATUS["LENGTH_REQUIRED"],
                'reason' => 'Content Length Required',
                'header' => ['Connection: close'],
            ]);
        } else {
            $responder = null;
        }

        return [$requestCycle, $responder];
    }

    private function onCompletedRequest(Client $client, array $parsedRequest) {
        unset($this->keepAliveTimeouts[$client->id]);

        if ($requestCycle = $client->partialCycle) {
            $responder = null;
            $this->updateRequestAfterEntity($requestCycle, $parsedRequest['headers']);
        } else {
            list($requestCycle, $responder) = $this->initializeCycle($client, $parsedRequest);
        }

        if ($responder) {
            $this->assignResponder($requestCycle, $responder);
        } else {
            $this->invokeHostApplication($requestCycle);
        }
    }

    private function updateRequestAfterEntity(RequestCycle $requestCycle, array $parsedHeadersArray) {
        $requestCycle->client->partialCycle = null;

        if ($needsNewRequestId = $requestCycle->expectsContinue) {
            $requestId = ++$this->lastRequestId;
            $requestCycle->requestId = $requestId;
            $requestCycle->client->cycles[$requestId] = $requestCycle;
            $this->requestIdClientMap[$requestId] = $requestCycle->client;
        }

        if (isset($requestCycle->request['HTTP_TRAILERS'])) {
            $this->updateTrailerHeaders($requestCycle, $parsedHeadersArray);
        }

        $contentType = isset($requestCycle->request['CONTENT_TYPE'])
            ? $requestCycle->request['CONTENT_TYPE']
            : null;

        if (stripos($contentType, 'application/x-www-form-urlencoded') === 0) {
            $bufferedBody = stream_get_contents($requestCycle->body);
            parse_str($bufferedBody, $requestCycle->request['FORM']);
            rewind($requestCycle->body);
        }
    }

    /**
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.40
     */
    private function updateTrailerHeaders(RequestCycle $requestCycle, array $headers) {
        // The Host header is ignored in trailers to prevent unsanitized values from bypassing the
        // original safety check when headers are first processed. The other values are expressly
        // disallowed by RFC 2616 Section 14.40.
        $disallowedHeaders = ['HOST', 'TRANSFER-ENCODING', 'CONTENT-LENGTH', 'TRAILER'];
        foreach (array_keys($headers) as $field) {
            $ucField = strtoupper($field);
            if (!in_array($ucField, $disallowedHeaders)) {
                $value = $headers[$field];
                $value = isset($value[1]) ? implode(',', $value) : $value[0];
                $key = 'HTTP_' . str_replace('-', '_', $ucField);
                $requestCycle->request[$key] = $value;
            }
        }
    }

    private function invokeHostApplication(RequestCycle $requestCycle) {
        try {
            $application = $requestCycle->vhost->getApplication();
            $responder = $application($requestCycle->request);
        } catch (\Exception $error) {
            $responder = $this->generateErrorResponder($error);
        } finally {
            $this->assignResponder($requestCycle, $responder);
        }
    }

    private function generateErrorResponder(\Exception $error) {
        $isDebugEnabled = $this->debug;

        if (empty($isDebugEnabled)) {
            $this->log($error);
        }

        $display = $isDebugEnabled ? "<pre>{$error}</pre>" : '<p>Something went terribly wrong</p>';
        $status = HTTP_STATUS["INTERNAL_SERVER_ERROR"];
        $reason = HTTP_REASON[$status];
        $body = "<html><body><h1>{$status} {$reason}</h1><p>{$display}</p></body></html>";

        return $this->responderFactory->make([
            'status' => $status,
            'reason' => $reason,
            'body'   => $body,
        ]);
    }

    private function assignResponder(RequestCycle $requestCycle, Responder $responder) {
        $requestCycle->responder = $responder;
        $client = $requestCycle->client;
        $this->hydrateClientResponderPipeline($client);

        // If there's already a pending responder we need to wait until it completes before
        // dispatching the next one ...
        if ($client->pendingResponder) {
            return;
        }

        $nextRequestId = key($client->cycles);
        if (isset($client->pipeline[$nextRequestId])) {
            $this->cedeResponderSocketControl($client, $nextRequestId);
        }
    }

    private function hydrateClientResponderPipeline(Client $client) {
        foreach ($client->cycles as $requestId => $requestCycle) {
            if (isset($client->pipeline[$requestId])) {
                continue;
            } elseif ($requestCycle->responder) {
                $responderEnv = $this->makeResponderEnvironment($requestCycle);
                $responder = $requestCycle->responder;
                $responder->prepare($responderEnv);
                $client->pipeline[$requestId] = $responder;
            } else {
                break;
            }
        }

        // IMPORTANT: reset these arrays to avoid sending pipelined responses out of order
        reset($client->cycles);
        reset($client->pipeline);
    }

    private function cedeResponderSocketControl(Client $client, $requestId) {
        $requestCycle = $client->cycles[$requestId];
        $responder = $client->pipeline[$requestId];
        $client->pendingResponder = $responder;
        $responder->assumeSocketControl();
    }

    private function makeResponderEnvironment(RequestCycle $requestCycle) {
        $client = $requestCycle->client;

        $env = new ResponderEnvironment;
        $env->reactor = $this->reactor;
        $env->server = $this;
        $env->socket = $client->socket;
        $env->writeWatcher = $client->writeWatcher;
        $env->requestId = $requestCycle->requestId;
        $env->request = $requestCycle->request;

        $env->httpDate = $this->httpDateNow;
        $env->serverToken = $this->sendServerToken ? self::NAME : null;
        $env->defaultContentType = $this->defaultContentType;
        $env->defaultTextCharset = $this->defaultTextCharset;

        $protocol = $requestCycle->request['SERVER_PROTOCOL'];
        $reqConnHdr = isset($requestCycle->request['HTTP_CONNECTION'])
            ? $requestCycle->request['HTTP_CONNECTION']
            : null;

        if ($this->disableKeepAlive || $this->state === self::STOPPING) {
            // If keep-alive is disabled or the server is stopping we always close
            // after the response is written.
            $env->mustClose = true;
        } elseif ($this->maxRequests > 0 && $requestCycle->client->requestCount >= $this->maxRequests) {
            // If the client has exceeded the max allowable requests per connection
            // we always close after the response is written.
            $env->mustClose = true;
        } elseif (isset($reqConnHdr)) {
            // If the request indicated a close preference we agree to that. If the request uses
            // HTTP/1.0 we may still have to close if the response content length is unknown.
            // This potential need must be determined based on whether or not the response
            // content length is known at the time output starts.
            $env->mustClose = (stripos($reqConnHdr, 'close') !== false);
        } elseif ($protocol < 1.1) {
            // HTTP/1.0 defaults to a close after each response if not otherwise specified.
            $env->mustClose = true;
        } else {
            $env->mustClose = false;
        }

        if (!$env->mustClose) {
            $keepAlive = "timeout={$this->keepAliveTimeout}, max=";
            $keepAlive.= $this->maxRequests - $requestCycle->client->requestCount;
            $env->keepAlive = $keepAlive;
        }

        return $env;
    }

    private function clearClientReferences($client) {
        $this->reactor->cancel($client->readWatcher);
        $this->reactor->cancel($client->writeWatcher);

        unset(
            $this->clients[$client->id],
            $this->keepAliveTimeouts[$client->id]
        );

        if ($client->cycles) {
            foreach (array_keys($client->cycles) as $requestId) {
                unset($this->requestIdClientMap[$requestId]);
            }
        }

        $this->cachedClientCount--;
        $client->cycles = $client->pipeline = null;

        if ($this->state === self::PAUSED
            && $this->maxConnections > 0
            && $this->cachedClientCount <= $this->maxConnections
        ) {
            $this->resumeClientAcceptance();
        }
    }

    private function closeClient(Client $client) {
        $this->clearClientReferences($client);
        $this->doSocketClose($client->socket);

        // If we're shutting down and no more clients remain we can resolve our stop promise
        if ($this->state === self::STOPPING && empty($this->clients)) {
            $this->stopPromisor->succeed();
        }
    }

    private function doSocketClose($socket) {
        if (!is_resource($socket)) {
            return;
        }

        $socketId = (int) $socket;

        if (isset(stream_context_get_options($socket)['ssl'])) {
            @stream_socket_enable_crypto($socket, FALSE);
        }

        if ($this->socketSoLingerZero) {
            $this->closeSocketWithSoLingerZero($socket);
        } else {
            stream_socket_shutdown($socket, STREAM_SHUT_WR);
            @fread($socket, $this->readGranularity);
            @fclose($socket);
        }
    }

    private function closeSocketWithSoLingerZero($socket) {
        $socket = socket_import_stream($socket);
        socket_set_option($socket, SOL_SOCKET, SO_LINGER, [
            'l_onoff' => 1,
            'l_linger' => 0
        ]);

        socket_close($socket);
    }

    /**
     * Set multiple server options at once
     *
     * @param array $options
     * @throws \DomainException On unrecognized option key
     * @return void
     */
    public function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
    }

    /**
     * Set an individual server option directive
     *
     * @param int $option A server option constant
     * @param mixed $value The option value to assign
     * @throws \DomainException On unrecognized option key
     * @return void
     */
    public function setOption($option, $value) {
        switch ($option) {
            case self::OP_DEBUG:
                $this->setDebug($value); break;
            case self::OP_MAX_CONNECTIONS:
                $this->setMaxConnections($value); break;
            case self::OP_MAX_REQUESTS:
                $this->setMaxRequests($value); break;
            case self::OP_KEEP_ALIVE_TIMEOUT:
                $this->setKeepAliveTimeout($value); break;
            case self::OP_DISABLE_KEEP_ALIVE:
                $this->setDisableKeepAlive($value); break;
            case self::OP_MAX_HEADER_BYTES:
                $this->setMaxHeaderBytes($value); break;
            case self::OP_MAX_BODY_BYTES:
                $this->setMaxBodyBytes($value); break;
            case self::OP_DEFAULT_CONTENT_TYPE:
                $this->setDefaultContentType($value); break;
            case self::OP_DEFAULT_TEXT_CHARSET:
                $this->setDefaultTextCharset($value); break;
            case self::OP_SEND_SERVER_TOKEN:
                $this->setSendServerToken($value); break;
            case self::OP_NORMALIZE_METHOD_CASE:
                $this->setNormalizeMethodCase($value); break;
            case self::OP_REQUIRE_BODY_LENGTH:
                $this->setRequireBodyLength($value); break;
            case self::OP_SOCKET_SO_LINGER_ZERO:
                $this->setSocketSoLingerZero($value); break;
            case self::OP_SOCKET_BACKLOG_SIZE:
                $this->setSocketBacklogSize($value); break;
            case self::OP_ALLOWED_METHODS:
                $this->setAllowedMethods($value); break;
            case self::OP_DEFAULT_HOST:
                $this->setDefaultHost($value); break;
            default:
                throw new \DomainException(
                    "Unknown server option: {$option}"
                );
        }
    }

    private function setDebug($bool) {
        if ($this->state === self::STOPPED) {
            $this->debug = (bool) $bool;
        } else {
            throw new \LogicException(
                'Cannot modify debug setting; server is running'
            );
        }
    }

    private function setMaxConnections($maxConns) {
        $this->maxConnections = (int) $maxConns;
    }

    private function setMaxRequests($maxRequests) {
        $this->maxRequests = (int) $maxRequests;
    }

    private function setKeepAliveTimeout($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < -1) {
            $seconds = 10;
        }
    }

    private function setDisableKeepAlive($boolFlag) {
        $this->disableKeepAlive = (bool) $boolFlag;
    }

    private function setMaxHeaderBytes($bytes) {
        $this->maxHeaderBytes = (int) $bytes;
    }

    private function setMaxBodyBytes($bytes) {
        $this->maxBodyBytes = (int) $bytes;
    }

    private function setDefaultContentType($mimeType) {
        $this->defaultContentType = $mimeType;
    }

    private function setDefaultTextCharset($charset) {
        $this->defaultTextCharset = $charset;
    }

    private function setSendServerToken($boolFlag) {
        $this->sendServerToken = (bool) $boolFlag;
    }

    private function setNormalizeMethodCase($boolFlag) {
        $this->normalizeMethodCase = (bool) $boolFlag;
    }

    private function setRequireBodyLength($boolFlag) {
        $this->requireBodyLength = (bool) $boolFlag;
    }

    private function setSocketSoLingerZero($boolFlag) {
        $boolFlag = (bool) $boolFlag;

        if ($boolFlag && !$this->hasSocketsExtension) {
            throw new \RuntimeException(
                'Cannot enable socketSoLingerZero; PHP sockets extension required'
            );
        }

        $this->socketSoLingerZero = $boolFlag;
    }

    private function setSocketBacklogSize($size) {
        $size = (int) $size;
        if ($size <= 0) {
            $size = 128;
        }

        $this->socketBacklogSize = $size;

        return $size;
    }

    private function setAllowedMethods(array $methods) {
        if (is_string($methods)) {
            $methods = array_filter(array_map('trim', explode(' ', $methods)));
        }
        if (!($methods && is_array($methods))) {
            throw new \DomainException(
                'Allowed method assignment requires a comma delimited string or an array of HTTP methods'
            );
        }
        $methods = array_unique($methods);
        if (!in_array('GET', $methods)) {
            throw new \DomainException(
                'Cannot disallow GET method'
            );
        }
        if (!in_array('HEAD', $methods)) {
            throw new \DomainException(
                'Cannot disallow HEAD method'
            );
        }
        // @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec2.html#sec2.2
        // @TODO Validate characters in method names match the RFC 2616 ABNF token definition:
        // token          = 1*<any CHAR except CTLs or separators>
        $methods = array_filter($methods, function($m) { return $m && is_string($m); });
        $this->allowedMethods = array_combine($methods, array_fill(0, count($methods), 1));
    }

    private function setDefaultHost($vhostId) {
        $this->defaultHost = $vhostId;
    }

    /**
     * Retrieve a server option value
     *
     * @param int $option A server option constant
     * @throws \DomainException On unknown option
     * @return mixed The current value of the requested option
     */
    public function getOption($option) {
        switch ($option) {
            case self::OP_DEBUG:
                return $this->debug;
            case self::OP_MAX_CONNECTIONS:
                return $this->maxConnections;
            case self::OP_MAX_REQUESTS:
                return $this->maxRequests;
            case self::OP_KEEP_ALIVE_TIMEOUT:
                return $this->keepAliveTimeout;
            case self::OP_DISABLE_KEEP_ALIVE:
                return $this->disableKeepAlive;
            case self::OP_MAX_HEADER_BYTES:
                return $this->maxHeaderBytes;
            case self::OP_MAX_BODY_BYTES:
                return $this->maxBodyBytes;
            case self::OP_DEFAULT_CONTENT_TYPE:
                return $this->defaultContentType;
            case self::OP_DEFAULT_TEXT_CHARSET:
                return $this->defaultTextCharset;
            case self::OP_SEND_SERVER_TOKEN:
                return $this->sendServerToken;
            case self::OP_NORMALIZE_METHOD_CASE:
                return $this->normalizeMethodCase;
            case self::OP_REQUIRE_BODY_LENGTH:
                return $this->requireBodyLength;
            case self::OP_SOCKET_SO_LINGER_ZERO:
                return $this->socketSoLingerZero;
            case self::OP_SOCKET_BACKLOG_SIZE:
                return $this->socketBacklogSize;
            case self::OP_ALLOWED_METHODS:
                return array_keys($this->allowedMethods);
            case self::OP_DEFAULT_HOST:
                return $this->defaultHost;
            default:
                throw new \DomainException(
                    "Unknown server option: {$option}"
                );
        }
    }

    /**
     * Retrieve an indexed array mapping available options to their current values
     *
     * @return array
     */
    public function getAllOptions() {
        return [
            self::OP_DEBUG                  => $this->debug,
            self::OP_MAX_CONNECTIONS        => $this->maxConnections,
            self::OP_MAX_REQUESTS           => $this->maxRequests,
            self::OP_KEEP_ALIVE_TIMEOUT     => $this->keepAliveTimeout,
            self::OP_DISABLE_KEEP_ALIVE     => $this->disableKeepAlive,
            self::OP_MAX_HEADER_BYTES       => $this->maxHeaderBytes,
            self::OP_MAX_BODY_BYTES         => $this->maxBodyBytes,
            self::OP_DEFAULT_CONTENT_TYPE   => $this->defaultContentType,
            self::OP_DEFAULT_TEXT_CHARSET   => $this->defaultTextCharset,
            self::OP_SEND_SERVER_TOKEN      => $this->sendServerToken,
            self::OP_NORMALIZE_METHOD_CASE  => $this->normalizeMethodCase,
            self::OP_REQUIRE_BODY_LENGTH    => $this->requireBodyLength,
            self::OP_SOCKET_SO_LINGER_ZERO  => $this->socketSoLingerZero,
            self::OP_SOCKET_BACKLOG_SIZE    => $this->socketBacklogSize,
            self::OP_ALLOWED_METHODS        => array_keys($this->allowedMethods),
            self::OP_DEFAULT_HOST           => $this->defaultHost
        ];
    }

    public function __destruct() {
        foreach ($this->acceptWatchers as $watcherId) {
            $this->reactor->cancel($watcherId);
        }

        foreach ($this->pendingTlsWatchers as $watcherId) {
            $this->reactor->cancel($watcherId);
        }

        if ($this->keepAliveWatcher) {
            $this->reactor->cancel($this->keepAliveWatcher);
        }
    }
}
