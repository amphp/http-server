<?php

namespace Aerys;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\IteratorStream;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Promise;
use Amp\Success;
use function Amp\call;

class BodyParser implements Promise {
    const DEFAULT_MAX_BODY_SIZE = 131072;
    const DEFAULT_MAX_FIELD_LENGTH = 16384;
    const DEFAULT_MAX_INPUT_VARS = 200;

    /** @var \Amp\Promise|null */
    private $parsePromise;

    /** @var \Aerys\Request */
    private $request;

    /** @var \Aerys\Body */
    private $body;

    /** @var string|null */
    private $boundary;

    /** @var \Amp\Deferred[][] */
    private $bodyDeferreds = [];

    /** @var \Aerys\FieldBody[][] */
    private $bodies = [];

    /** @var string[] */
    private $fieldQueue = [];

    /** @var \Amp\Deferred|null */
    private $pendingRead = null;

    /** @var \Amp\Promise|null */
    private $incrementalParsePromise;

    private $maxFieldLength; // prevent buffering of arbitrary long names and fail instead
    private $maxInputVars; // prevent requests from creating arbitrary many fields causing lot of processing time
    private $inputVarCount = 0;

    /**
     * @param Request $request
     * @param int $size Maximum size the body can be in bytes.
     * @param int $maxFieldLength Maximum length of each individual field in bytes.
     * @param int $maxInputVars Maximum number of fields that the body may contain.
     */
    public function __construct(
        Request $request,
        int $size = self::DEFAULT_MAX_BODY_SIZE,
        int $maxFieldLength = self::DEFAULT_MAX_FIELD_LENGTH,
        int $maxInputVars = self::DEFAULT_MAX_INPUT_VARS
    ) {
        $this->request = $request;
        $type = $request->getHeader("content-type");
        $this->body = $request->getBody();
        $this->body->increaseMaxSize($size);
        $this->maxFieldLength = $maxFieldLength;
        $this->maxInputVars = $maxInputVars;

        if ($type !== null && strncmp($type, "application/x-www-form-urlencoded", \strlen("application/x-www-form-urlencoded"))) {
            if (!preg_match('#^\s*multipart/(?:form-data|mixed)(?:\s*;\s*boundary\s*=\s*("?)([^"]*)\1)?$#', $type, $matches)) {
                $this->request = null;
                $this->parsePromise = new Success(new ParsedBody([]));
                return;
            }

            $this->boundary = $matches[2];
        }
    }

    public function onResolve(callable $onResolved) {
        if ($this->parsePromise) {
            return $this->parsePromise->onResolve($onResolved);
        }

        if ($this->incrementalParsePromise) {
            $this->parsePromise = call(function () {
                yield $this->incrementalParsePromise; // Wait for incremental parsing to complete.

                // Send unconsumed data into a new ParsedBody instance.

                $fields = $metadata = [];

                $onResolve = static function ($e, $data) use (&$fields, &$key) {
                    $fields[$key][] = $data;
                };

                $metaOnResolve = static function ($e, $data) use (&$metadata, &$key) {
                    $metadata[$key][] = $data;
                };

                foreach ($this->bodies as $key => $bodies) {
                    foreach ($bodies as $body) {
                        /** @var \Aerys\FieldBody $body */
                        $body->buffer()->onResolve($onResolve);
                        $body->getMetadata()->onResolve($metaOnResolve);
                    }

                    if (isset($metadata[$key])) {
                        $metadata[$key] = array_filter($metadata[$key]);
                    }
                }

                return new ParsedBody($fields, array_filter($metadata));
            });

            return $this->parsePromise->onResolve($onResolved);
        }

        // Use a faster parsing algorithm if incremental parsing has not been requested.
        $this->parsePromise = call(function () {
            try {
                return $this->parse(yield $this->body->buffer());
            } finally {
                $this->result = null;
            }
        });

        $this->parsePromise->onResolve($onResolved);
    }

    private function parse(string $data): ParsedBody {
        // if we end up here, we haven't parsed anything at all yet, so do a quick parse
        if ($this->boundary !== null) {
            $fields = $metadata = [];

            // RFC 7578, RFC 2046 Section 5.1.1
            if (strncmp($data, "--$this->boundary\r\n", \strlen($this->boundary) + 4) !== 0) {
                return new ParsedBody([]);
            }

            $exp = explode("\r\n--$this->boundary\r\n", $data);
            $exp[0] = substr($exp[0], \strlen($this->boundary) + 4);
            $exp[count($exp) - 1] = substr(end($exp), 0, -\strlen($this->boundary) - 8);

            foreach ($exp as $entry) {
                list($rawheaders, $text) = explode("\r\n\r\n", $entry, 2);
                $headers = [];

                foreach (explode("\r\n", $rawheaders) as $header) {
                    $split = explode(":", $header, 2);
                    if (!isset($split[1])) {
                        return new ParsedBody([]);
                    }
                    $headers[strtolower($split[0])] = trim($split[1]);
                }

                $count = preg_match(
                    '#^\s*form-data(?:\s*;\s*(?:name\s*=\s*"([^"]+)"|filename\s*=\s*"([^"]+)"))+\s*$#',
                    $headers["content-disposition"] ?? "",
                    $matches
                );

                if (!$count || !isset($matches[1])) {
                    return new ParsedBody([]);
                }
                $name = $matches[1];
                $fields[$name][] = $text;
                $this->fieldQueue[] = $name;

                // Ignore Content-Transfer-Encoding as deprecated and hence we won't support it

                if (isset($matches[2])) {
                    $metadata[$name][count($fields[$name]) - 1] = [
                        "filename" => $matches[2],
                        "mime" => $headers["content-type"] ?? "application/octet-stream"
                    ];
                } elseif (isset($headers["content-type"])) {
                    $metadata[$name][count($fields[$name]) - 1]["mime"] = $headers["content-type"];
                }
            }

            return new ParsedBody($fields, $metadata);
        }

        $fields = [];
        foreach (explode("&", $data) as $pair) {
            $pair = explode("=", $pair, 2);
            $field = urldecode($pair[0]);
            $fields[$field][] = urldecode($pair[1] ?? "");
            $this->fieldQueue[] = $field;
        }
        return new ParsedBody($fields, []);
    }

    public function fetch(): Promise {
        if ($this->parsePromise) {
            throw new \Error("Cannot read fields incrementally if buffering the entire request body");
        }

        if ($this->pendingRead) {
            throw new \Error("Field fetch request still pending");
        }

        if (!empty($this->fieldQueue)) {
            $key = \key($this->fieldQueue);
            $name = $this->fieldQueue[$key];
            unset($this->fieldQueue[$key]);
            return new Success($name);
        }

        if ($this->request) {
            $this->pendingRead = new Deferred;
            $promise = $this->pendingRead->promise();

            if (!$this->incrementalParsePromise) {
                $this->incrementalParsePromise = new Coroutine($this->incrementalParse());
            }

            return $promise;
        }

        return new Success;
    }

    /**
     * @param string $name field name
     * @return FieldBody
     */
    public function stream(string $name): FieldBody {
        if ($this->request) {
            if (!$this->incrementalParsePromise) {
                $this->incrementalParsePromise = new Coroutine($this->incrementalParse());
            }
            if (empty($this->bodies[$name])) {
                $this->bodyDeferreds[$name][] = [$body = new Emitter, $metadata = new Deferred];
                return new FieldBody($name, new IteratorStream($body->iterate()), $metadata->promise());
            }
        } elseif (empty($this->bodies[$name])) {
            return new FieldBody($name, new InMemoryStream, new Success([]));
        }

        $key = key($this->bodies[$name]);
        $ret = $this->bodies[$name][$key];
        unset($this->bodies[$name][$key]);
        return $ret;
    }

    private function initField(string $field, array $metadata = []): Emitter {
        if ($this->inputVarCount++ == $this->maxInputVars || \strlen($field) > $this->maxFieldLength) {
            throw new ClientException;
        }

        if (isset($this->bodyDeferreds[$field])) {
            $key = key($this->bodyDeferreds[$field]);
            /**
             * @var \Amp\Emitter $dataEmitter
             * @var \Amp\Deferred $metadataDeferred
             */
            list($dataEmitter, $metadataDeferred) = $this->bodyDeferreds[$field][$key];
            $metadataDeferred->resolve($metadata);
            unset($this->bodyDeferreds[$field][$key]);
        } else {
            $dataEmitter = new Emitter;
            $body = new FieldBody($field, new IteratorStream($dataEmitter->iterate()), new Success($metadata));
            $this->bodies[$field][] = $body;
        }

        if ($this->pendingRead) {
            $pendingRead = $this->pendingRead;
            $this->pendingRead = null;
            $pendingRead->resolve($field);
        } else {
            $this->fieldQueue[] = $field;
        }

        return $dataEmitter;
    }

    private function incrementalParse(): \Generator {
        try {
            if ($this->boundary) {
                yield from $this->incrementalBoundaryParse();
            } else {
                yield from $this->incrementalFieldParse();
            }
        } catch (\Throwable $exception) {
            /**
             * @var \Amp\Emitter $emitter
             * @var \Amp\Deferred $metadata
             */
            foreach ($this->bodyDeferreds as list($emitter, $metadata)) {
                $emitter->fail($exception);
                $metadata->fail($exception);
            }

            $this->bodyDeferreds = [];

            if ($this->pendingRead) {
                $pendingRead = $this->pendingRead;
                $this->pendingRead = null;
                $pendingRead->fail($exception);
            }

            throw $exception;
        } finally {
            $this->request = null;
        }

        foreach ($this->bodyDeferreds as $fieldArray) {
            /**
             * @var \Amp\Emitter $emitter
             * @var \Amp\Deferred $metadata
             */
            foreach ($fieldArray as list($emitter, $metadata)) {
                $emitter->complete();
                $metadata->resolve([]);
            }
        }

        if ($this->pendingRead) {
            $pendingRead = $this->pendingRead;
            $this->pendingRead = null;
            $pendingRead->resolve();
        }
    }

    private function incrementalBoundaryParse(): \Generator {
        $buffer = "";

        // RFC 7578, RFC 2046 Section 5.1.1
        $sep = "--$this->boundary";
        while (\strlen($buffer) < \strlen($sep) + 4) {
            $buffer .= $chunk = yield $this->body->read();
            if ($chunk === null) {
                throw new ClientException;
            }
        }
        $off = \strlen($sep);
        if (strncmp($buffer, $sep, $off)) {
            throw new ClientException;
        }

        $sep = "\r\n$sep";

        while (substr_compare($buffer, "--\r\n", $off)) {
            $off += 2;

            while (($end = strpos($buffer, "\r\n\r\n", $off)) === false) {
                $buffer .= $chunk = yield $this->body->read();
                if ($chunk === null) {
                    throw new ClientException;
                }
            }

            $headers = [];

            foreach (explode("\r\n", substr($buffer, $off, $end - $off)) as $header) {
                $split = explode(":", $header, 2);
                if (!isset($split[1])) {
                    throw new ClientException;
                }
                $headers[strtolower($split[0])] = trim($split[1]);
            }

            $count = preg_match(
                '#^\s*form-data(?:\s*;\s*(?:name\s*=\s*"([^"]+)"|filename\s*=\s*"([^"]+)"))+\s*$#',
                $headers["content-disposition"] ?? "",
                $matches
            );

            if (!$count || !isset($matches[1])) {
                throw new ClientException;
            }
            $field = $matches[1];

            // Ignore Content-Transfer-Encoding as deprecated and hence we won't support it

            if (isset($matches[2])) {
                $metadata = [
                    "filename" => $matches[2],
                    "mime" => $headers["content-type"] ?? "application/octet-stream"
                ];
            } elseif (isset($headers["content-type"])) {
                $metadata = ["mime" => $headers["content-type"]];
            } else {
                $metadata = [];
            }

            $dataEmitter = $this->initField($field, $metadata);

            $buffer = substr($buffer, $end + 4);
            $off = 0;

            while (($end = strpos($buffer, $sep, $off)) === false) {
                $buffer .= $chunk = yield $this->body->read();
                if ($chunk === null) {
                    $e = new ClientException;
                    $dataEmitter->fail($e);
                    throw $e;
                }

                if (\strlen($buffer) > \strlen($sep)) {
                    $off = \strlen($buffer) - \strlen($sep);
                    $data = substr($buffer, 0, $off);
                    $dataEmitter->emit($data);
                    $buffer = substr($buffer, $off);
                }
            }

            $data = substr($buffer, 0, $end);
            $dataEmitter->emit($data);
            $dataEmitter->complete();
            $off = $end + \strlen($sep);

            while (\strlen($buffer) < 4) {
                $buffer .= $chunk = yield $this->body->read();
                if ($chunk === null) {
                    throw new ClientException;
                }
            }
        }
    }

    private function incrementalFieldParse(): \Generator {
        $buffer = "";
        $noData = false;
        $field = null;

        /** @var \Amp\Emitter|null $dataEmitter */
        $dataEmitter = null;

        while (($new = yield $this->body->read()) !== null) {
            if ($new[0] === "&") {
                if ($field !== null) {
                    if ($noData || $buffer !== "") {
                        $data = urldecode($buffer);
                        $dataEmitter->emit($data);
                        $buffer = "";
                    }
                    $field = null;
                    $dataEmitter->complete();
                } elseif ($buffer !== "") {
                    $dataEmitter = $this->initField(urldecode($buffer));
                    $dataEmitter->emit("");
                    $dataEmitter->complete();
                    $buffer = "";
                }
            }

            $buffer .= strtok($new, "&");
            if ($field !== null && ($new = strtok("&")) !== false) {
                $data = urldecode($buffer);
                $dataEmitter->emit($data);
                $dataEmitter->complete();

                $buffer = $new;
                $field = null;
            }

            while (($next = strtok("&")) !== false) {
                $pair = explode("=", $buffer, 2);
                $key = urldecode($pair[0]);
                $dataEmitter = $this->initField($key);
                $data = urldecode($pair[1] ?? "");
                $dataEmitter->emit($data);
                $dataEmitter->complete();

                $buffer = $next;
            }

            if ($field === null) {
                if (($new = strstr($buffer, "=", true)) !== false) {
                    $field = urldecode($new);
                    $dataEmitter = $this->initField($field);
                    $buffer = substr($buffer, \strlen($new) + 1);
                    $noData = true;
                } elseif (\strlen($buffer) > $this->maxFieldLength) {
                    throw new ClientException;
                }
            }

            if ($field !== null && $buffer !== "" && (\strlen($buffer) > 2 || $buffer[0] !== "%")) {
                if (\strlen($buffer) > 1 ? false !== $percent = strrpos($buffer, "%", -2) : !($percent = $buffer[0] !== "%")) {
                    if ($percent) {
                        $dataEmitter->emit(urldecode(substr($buffer, 0, $percent)));
                        $buffer = substr($buffer, $percent);
                    }
                } else {
                    $data = urldecode($buffer);
                    $dataEmitter->emit($data);
                    $buffer = "";
                }
                $noData = false;
            }
        }

        if ($field !== null) {
            if ($noData || $buffer) {
                $data = urldecode($buffer);
                $dataEmitter->emit($data);
            }
            $dataEmitter->complete();
            $field = null;
        } elseif ($buffer) {
            $field = urldecode($buffer);
            $dataEmitter = $this->initField($field);
            $dataEmitter->emit("");
            $dataEmitter->complete();
        }
    }
}
