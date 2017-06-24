<?php

namespace Aerys;

use Amp\{ Coroutine, Deferred, Emitter, Internal\Placeholder, Promise, Success };
use Amp\ByteStream\{ InputStream, PendingReadError };

class BodyParser implements InputStream, Promise {
    use Placeholder {
        onResolve as private when;
    }
    // use $this->onResolved for checking whether such a handler was installed

    /** @var \Aerys\Request */
    private $req;

    /** @var \Aerys\Body */
    private $body;

    private $boundary = null;

    private $bodyDeferreds = [];
    private $bodies = [];

    private $fieldQueue = [];
    private $pendingRead = null;

    private $startedParsing = false;

    private $size;
    private $totalSize;
    private $usedSize = 0;
    private $sizes = [];
    private $curSizes = [];

    private $maxFieldLen; // prevent buffering of arbitrary long names and fail instead
    private $maxInputVars; // prevent requests from creating arbitrary many fields causing lot of processing time
    private $inputVarCount = 0;

    /**
     * @param Request $req
     * @param array $options available options are:
     *                       - size (default: 131072)
     *                       - input_vars (default: 200)
     *                       - field_len (default: 16384)
     */
    public function __construct(Request $req, array $options = []) {
        $this->req = $req;
        $type = $req->getHeader("content-type");
        $this->body = $req->getBody($this->totalSize = $this->size = $options["size"] ?? 131072);
        $this->maxFieldLen = $options["field_len"] ?? 16384;
        $this->maxInputVars = $options["input_vars"] ?? 200;

        if ($type !== null && strncmp($type, "application/x-www-form-urlencoded", \strlen("application/x-www-form-urlencoded"))) {
            if (!preg_match('#^\s*multipart/(?:form-data|mixed)(?:\s*;\s*boundary\s*=\s*("?)([^"]*)\1)?$#', $type, $m)) {
                $this->req = null;
                $this->startedParsing = true;
                $this->resolve(new ParsedBody([]));
                return;
            }

            $this->boundary = $m[2];
        }

    }

    public function onResolve(callable $onResolved) {
        if (!$this->onResolved) {
            \Amp\asyncCall(function () {
                try {
                    $this->resolve($this->end(yield $this->body));
                } catch (\Throwable $exception) {
                    if ($exception instanceof ClientSizeException) {
                        $exception = new ClientException("", 0, $exception);
                    }
                    $this->error($exception);
                } finally {
                    $this->req = null;
                }
            });
        }

        $this->when($onResolved);
    }

    private function end($data): ParsedBody {
        if (!$this->startedParsing) {
            $this->startedParsing = true;

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

                    if (!preg_match('#^\s*form-data(?:\s*;\s*(?:name\s*=\s*"([^"]+)"|filename\s*=\s*"([^"]+)"))+\s*$#', $headers["content-disposition"] ?? "", $m) || !isset($m[1])) {
                        return new ParsedBody([]);
                    }
                    $name = $m[1];
                    $fields[$name][] = $text;
                    $this->fieldQueue[] = $name;

                    // Ignore Content-Transfer-Encoding as deprecated and hence we won't support it

                    if (isset($m[2])) {
                        $metadata[$name][count($fields[$name]) - 1] = ["filename" => $m[2], "mime" => $headers["content-type"] ?? "application/octet-stream"];
                    } elseif (isset($headers["content-type"])) {
                        $metadata[$name][count($fields[$name]) - 1]["mime"] = $headers["content-type"];
                    }
                }

                return new ParsedBody($fields, $metadata);

            } else {
                $fields = [];
                foreach (explode("&", $data) as $pair) {
                    $pair = explode("=", $pair, 2);
                    $field = urldecode($pair[0]);
                    $fields[$field][] = urldecode($pair[1] ?? "");
                    $this->fieldQueue[] = $field;
                }
                return new ParsedBody($fields, []);
            }
        } else {
            $fields = $metadata = [];
            $when = static function($e, $data) use (&$fields, &$key) {
                $fields[$key][] = $data;
            };
            $metawhen = static function($e, $data) use (&$metadata, &$key) {
                $metadata[$key][] = $data;
            };

            foreach ($this->bodies as $key => $bodies) {
                foreach ($bodies as $body) {
                    $body->onResolve($when);
                    $body->getMetadata()->onResolve($metawhen);
                }
                $metadata[$key] = array_filter($metadata[$key]);
            }

            return new ParsedBody($fields, array_filter($metadata));
        }
    }

    public function read(): Promise {
        if ($this->pendingRead) {
            throw new PendingReadError;
        }

        if (!empty($this->fieldQueue)) {
            $key = \key($this->fieldQueue);
            $val = $this->fieldQueue[$key];
            unset($this->fieldQueue[$key]);
            return new Success($val);
        } elseif ($this->req) {
            $this->pendingRead = new Deferred;
            $promise = $this->pendingRead->promise();

            if (!$this->startedParsing) {
                Promise\rethrow(new Coroutine($this->initIncremental()));
            }

            return $promise;
        }

        return new Success;
    }

    /**
     * @param string $name field name
     * @param int $size <= 0: use last size, if none present, count toward total size, else separate size just respecting value size
     * @return FieldBody
     */
    public function write(string $name, int $size = 0): FieldBody {
        if ($this->req) {
            if ($size > 0) {
                if (!empty($this->curSizes)) {
                    foreach ($this->curSizes[$name] as $partialSize) {
                        $size -= $partialSize;
                        if (!isset($this->sizes[$name])) {
                            $this->usedSize -= $partialSize - \strlen($name);
                        }
                    }
                }
                $this->sizes[$name] = $size;
                $this->body = $this->req->getBody($this->totalSize += $size);
            }
            if (!$this->startedParsing) {
                Promise\rethrow(new Coroutine($this->initIncremental()));
            }
            if (empty($this->bodies[$name])) {
                $this->bodyDeferreds[$name][] = [$body = new Emitter, $metadata = new Deferred];
                return new FieldBody($body->iterate(), $metadata->promise());
            }
        } elseif (empty($this->bodies[$name])) {
            $emptyEmitter = new Emitter;
            $emptyEmitter->complete();
            return new FieldBody($emptyEmitter->iterate(), new Success([]));
        }
        
        $key = key($this->bodies[$name]);
        $ret = $this->bodies[$name][$key];
        unset($this->bodies[$name][$key], $this->curSizes[$name][$key]);
        return $ret;
    }

    private function initField($field, $metadata = []) {
        if ($this->inputVarCount++ == $this->maxInputVars || \strlen($field) > $this->maxFieldLen) {
            $this->error();
            return null;
        }

        $this->curSizes[$field] = 0;
        $this->usedSize += \strlen($field);
        if ($this->usedSize > $this->size) {
            $this->error();
            return null;
        }
        
        if (isset($this->bodyDeferreds[$field])) {
            $key = key($this->bodyDeferreds[$field]);
            list($dataEmitter, $metadataDeferred) = $this->bodyDeferreds[$field][$key];
            $metadataDeferred->resolve($metadata);
            unset($this->bodyDeferreds[$field]);
        } else {
            $dataEmitter = new Emitter;
            $this->bodies[$field][] = new FieldBody($dataEmitter->iterate(), new Success($metadata));
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

    private function updateFieldSize($field, $data) {
        $this->curSizes[$field] += \strlen($data);
        if (isset($this->sizes[$field])) {
            if ($this->curSizes[$field] > $this->sizes[$field]) {
                $this->error();
                return true;
            }
        } else {
            $this->usedSize += \strlen($data);
            if ($this->usedSize > $this->size) {
                $this->error();
                return true;
            }
        }
        return false;
    }

    private function error(\Throwable $e = null) {
        $e = $e ?? new ClientSizeException;
        foreach ($this->bodyDeferreds as list($emitter, $metadata)) {
            $emitter->fail($e);
            $metadata->fail($e);
        }
        $this->bodyDeferreds = [];
        $this->req = null;

        $this->fail($e);
    }

    private function initIncremental() {
        $this->startedParsing = true;

        $buf = "";
        if ($this->boundary) {
            // RFC 7578, RFC 2046 Section 5.1.1
            $sep = "--$this->boundary";
            while (\strlen($buf) < \strlen($sep) + 4) {
                $buf .= $chunk = yield $this->body->read();
                if ($chunk == "") {
                    $this->error(new ClientException);
                    return;
                }
            }
            $off = \strlen($sep);
            if (strncmp($buf, $sep, $off)) {
                $this->error(new ClientException);
                return;
            }

            $sep = "\r\n$sep";
            
            while (substr_compare($buf, "--\r\n", $off)) {
                $off += 2;
                
                while (($end = strpos($buf, "\r\n\r\n", $off)) === false) {
                    $buf .= $chunk = yield $this->body->read();
                    if ($chunk == "") {
                        $this->error(new ClientException);
                        return;
                    }
                }

                $headers = [];

                foreach (explode("\r\n", substr($buf, $off, $end - $off)) as $header) {
                    $split = explode(":", $header, 2);
                    if (!isset($split[1])) {
                        $this->error(new ClientException);
                        return;
                    }
                    $headers[strtolower($split[0])] = trim($split[1]);
                }

                if (!preg_match('#^\s*form-data(?:\s*;\s*(?:name\s*=\s*"([^"]+)"|filename\s*=\s*"([^"]+)"))+\s*$#', $headers["content-disposition"] ?? "", $m) || !isset($m[1])) {
                    $this->error(new ClientException);
                    return;
                }
                $field = $m[1];

                // Ignore Content-Transfer-Encoding as deprecated and hence we won't support it

                if (isset($m[2])) {
                    $metadata = ["filename" => $m[2], "mime" => $headers["content-type"] ?? "application/octet-stream"];
                } elseif (isset($headers["content-type"])) {
                    $metadata = ["mime" => $headers["content-type"]];
                } else {
                    $metadata = [];
                }
                $dataEmitter = $this->initField($field, $metadata);

                $buf = substr($buf, $end + 4);
                $off = 0;
                
                while (($end = strpos($buf, $sep, $off)) === false) {
                    $buf .= $chunk = yield $this->body->read();
                    if ($chunk == "") {
                        $e = new ClientException;
                        $dataEmitter->fail($e);
                        $this->error($e);
                        return;
                    }

                    if (\strlen($buf) > \strlen($sep)) {
                        $off = \strlen($buf) - \strlen($sep);
                        $data = substr($buf, 0, $off);
                        if ($this->updateFieldSize($field, $data)) {
                            $dataEmitter->fail(new ClientSizeException);
                            return;
                        }
                        $dataEmitter->emit($data);
                        $buf = substr($buf, $off);
                    }
                }

                $data = substr($buf, 0, $end);
                if ($this->updateFieldSize($field, $data)) {
                    $dataEmitter->fail(new ClientSizeException);
                    return;
                }
                $dataEmitter->emit($data);
                $dataEmitter->complete();
                $off = $end + \strlen($sep);

                while (\strlen($buf) < 4) {
                    $buf .= $chunk = yield $this->body->read();
                    if ($chunk == "") {
                        $this->error(new ClientException);
                        return;
                    }
                }
            }
        } else {
            $field = null;
            while (($new = yield $this->body->read()) != "") {
                if ($new[0] === "&") {
                    if ($field !== null) {
                        if ($noData || $buf != "") {
                            $data = urldecode($buf);
                            if ($this->updateFieldSize($field, $data)) {
                                $dataEmitter->fail(new ClientSizeException);
                                return;
                            }
                            $dataEmitter->emit($data);
                            $buf = "";
                        }
                        $field = null;
                        $dataEmitter->complete();
                    } elseif ($buf != "") {
                        if (!$dataEmitter = $this->initField(urldecode($buf))) {
                            return;
                        }
                        $dataEmitter->emit("");
                        $dataEmitter->complete();
                        $buf = "";
                    }
                }

                $buf .= strtok($new, "&");
                if ($field !== null && ($new = strtok("&")) !== false) {
                    $data = urldecode($buf);
                    if ($this->updateFieldSize($field, $data)) {
                        $dataEmitter->fail(new ClientSizeException);
                        return;
                    }
                    $dataEmitter->emit($data);
                    $dataEmitter->complete();

                    $buf = $new;
                    $field = null;
                }

                while (($next = strtok("&")) !== false) {
                    $pair = explode("=", $buf, 2);
                    $key = urldecode($pair[0]);
                    if (!$dataEmitter = $this->initField($key)) {
                        return;
                    }
                    $data = urldecode($pair[1] ?? "");
                    if ($this->updateFieldSize($key, $data)) {
                        $dataEmitter->fail(new ClientSizeException);
                        return;
                    }
                    $dataEmitter->emit($data);
                    $dataEmitter->complete();

                    $buf = $next;
                }

                if ($field === null) {
                    if (($new = strstr($buf, "=", true)) !== false) {
                        $field = urldecode($new);
                        if (!$dataEmitter = $this->initField($field)) {
                            return;
                        }
                        $buf = substr($buf, \strlen($new) + 1);
                        $noData = true;
                    } elseif (\strlen($buf) > $this->maxFieldLen) {
                        $this->error();
                        return;
                    }
                }

                if ($field !== null && $buf != "" && (\strlen($buf) > 2 || $buf[0] !== "%")) {
                    if (\strlen($buf) > 1 ? false !== $percent = strrpos($buf, "%", -2) : !($percent = $buf[0] !== "%")) {
                        if ($percent) {
                            if ($this->updateFieldSize($field, $data)) {
                                return;
                            }
                            $dataEmitter->emit(urldecode(substr($buf, 0, $percent)));
                            $buf = substr($buf, $percent);
                        }
                    } else {
                        $data = urldecode($buf);
                        if ($this->updateFieldSize($field, $data)) {
                            $dataEmitter->fail(new ClientSizeException);
                            return;
                        }
                        $dataEmitter->emit($data);
                        $buf = "";
                    }
                    $noData = false;
                }
            }

            if ($field !== null) {
                if ($noData || $buf) {
                    $data = urldecode($buf);
                    if ($this->updateFieldSize($field, $data)) {
                        return;
                    }
                    $dataEmitter->emit($data);
                }
                $dataEmitter->complete();
                $field = null;
            } elseif ($buf) {
                $field = urldecode($buf);
                if (!$dataEmitter = $this->initField($field)) {
                    return;
                }
                $dataEmitter->emit("");
                $dataEmitter->complete();
            }
        }

        foreach ($this->bodyDeferreds as $fieldArray) {
            foreach ($fieldArray as list($emitter, $metadata)) {
                $emitter->complete();
                $metadata->resolve([]);
            }
        }

        $this->req = null;

        if ($this->pendingRead) {
            $pendingRead = $this->pendingRead;
            $this->pendingRead = null;
            $pendingRead->resolve(null);
        }
    }

    // ...
    public function close() {
        throw new \Error("Must not call BodyParser::close()");
    }
}
