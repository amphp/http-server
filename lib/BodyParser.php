<?php

namespace Aerys;

use Amp\Deferred;
use Amp\Promise;
use Amp\Success;

class BodyParser implements Promise {
    private $req;
    private $body;
    private $boundary = null;

    private $whens = [];
    private $watchers = [];
    private $error = null;
    private $result = null;

    private $bodyPromisors = [];
    private $bodies = [];
    private $parsing = false;

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
                $this->parsing = true;
                $this->result = new ParsedBody([]);
                return;
            }

            $this->boundary = $m[2];
        }

        $this->body->when(function ($e, $data) {
            $this->req = null;
            \Amp\immediately(function() use ($e, $data) {
                if ($e) {
                    if ($e instanceof ClientSizeException) {
                        $e = new ClientException("", 0, $e);
                    }
                    $this->error = $e;
                } else {
                    $this->result = $this->end($data);

                    if (!$this->parsing) {
                        $this->parsing = true;
                        foreach ($this->result->getNames() as $field) {
                            foreach ($this->result->getArray($field) as $_) {
                                foreach ($this->watchers as list($cb, $cbData)) {
                                    $cb($field, $cbData);
                                }
                            }
                        }
                    }
                }

                $this->parsing = true;
                
                foreach ($this->whens as list($cb, $cbData)) {
                    $cb($this->error, $this->result, $cbData);
                }
                
                $this->whens = $this->watchers = [];
            });
        });
    }

    private function end($data) {
        if (!$this->bodies && $data != "") {
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
                    $fields[urldecode($pair[0])][] = urldecode($pair[1] ?? "");
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
                    $body->when($when);
                    $body->getMetadata()->when($metawhen);
                }
                $metadata[$key] = array_filter($metadata[$key]);
            }
            
            return new ParsedBody($fields, array_filter($metadata));
        }
    }

    public function when(callable $cb, $cbData = null) {
        if ($this->req || !$this->parsing) {
            $this->whens[] = [$cb, $cbData];
        } else {
            $cb($this->error, $this->result, $cbData);
        }
        
        return $this;
    }

    public function watch(callable $cb, $cbData = null) {
        if ($this->req) {
            $this->watchers[] = [$cb, $cbData];

            if (!$this->parsing) {
                $this->parsing = true;
                \Amp\immediately(function() { return $this->initIncremental(); });
            }
        } elseif (!$this->parsing) {
            $this->watchers[] = [$cb, $cbData];
        }
        
        return $this;
    }

    /**
     * @param string $name field name
     * @param int $size <= 0: use last size, if none present, count toward total size, else separate size just respecting value size
     * @return FieldBody
     */
    public function stream(string $name, int $size = 0): FieldBody {
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
            if (!$this->parsing) {
                $this->parsing = true;
                \Amp\immediately(function() { return $this->initIncremental(); });
            }
            if (empty($this->bodies[$name])) {
                $this->bodyPromisors[$name][] = [$body = new Deferred, $metadata = new Deferred];
                return new FieldBody($body->promise(), $metadata->promise());
            }
        } elseif (empty($this->bodies[$name])) {
            return new FieldBody(new Success, new Success([]));
        }
        
        $key = key($this->bodies[$name]);
        $ret = $this->bodies[$name][$key];
        unset($this->bodies[$name][$key], $this->curSizes[$name][$key]);
        return $ret;
    }

    private function initField($field, $metadata = []) {
        if ($this->inputVarCount++ == $this->maxInputVars || \strlen($field) > $this->maxFieldLen) {
            $this->fail();
            return null;
        }

        $this->curSizes[$field] = 0;
        $this->usedSize += \strlen($field);
        if ($this->usedSize > $this->size) {
            $this->fail();
            return null;
        }
        
        if (isset($this->bodyPromisors[$field])) {
            $key = key($this->bodyPromisors[$field]);
            list($dataPromisor, $metadataPromisor) = $this->bodyPromisors[$field][$key];
            $metadataPromisor->succeed($metadata);
            unset($this->bodyPromisors[$field]);
        } else {
            $dataPromisor = new Deferred;
            $this->bodies[$field][] = new FieldBody($dataPromisor->promise(), new Success($metadata));
        }
        
        foreach ($this->watchers as list($cb, $cbData)) {
            $cb($field, $cbData);
        }
        return $dataPromisor;
    }

    private function updateFieldSize($field, $data) {
        $this->curSizes[$field] += \strlen($data);
        if (isset($this->sizes[$field])) {
            if ($this->curSizes[$field] > $this->sizes[$field]) {
                $this->fail();
                return true;
            }
        } else {
            $this->usedSize += \strlen($data);
            if ($this->usedSize > $this->size) {
                $this->fail();
                return true;
            }
        }
        return false;
    }

    private function fail($e = null) {
        $this->error = $e ?? $e = new ClientSizeException;
        foreach ($this->bodyPromisors as list($promisor, $metadata)) {
            $promisor->fail($e);
            $metadata->fail($e);
        }
        $this->bodyPromisors = [];
        $this->req = null;

        foreach ($this->whens as list($cb, $cbData)) {
            $cb($e, null, $cbData);
        }

        $this->whens = $this->watchers = [];
    }

    // this should be inside an immediate (not direct resolve) to give user a chance to install watch() handlers
    private function initIncremental() {
        $buf = "";
        if ($this->boundary) {
            // RFC 7578, RFC 2046 Section 5.1.1
            $sep = "--$this->boundary";
            while (\strlen($buf) < \strlen($sep) + 4) {
                if (!yield $this->body->valid()) {
                    return $this->fail(new ClientException);
                }
                $buf .= $this->body->consume();
            }
            $off = \strlen($sep);
            if (strncmp($buf, $sep, $off)) {
                return $this->fail(new ClientException);
            }

            $sep = "\r\n$sep";
            
            while (substr_compare($buf, "--\r\n", $off)) {
                $off += 2;
                
                while (($end = strpos($buf, "\r\n\r\n", $off)) === false) {
                    if (!yield $this->body->valid()) {
                        return $this->fail(new ClientException);
                    }
                    $off = \strlen($buf);
                    $buf .= $this->body->consume();
                }

                $headers = [];

                foreach (explode("\r\n", substr($buf, $off, $end - $off)) as $header) {
                    $split = explode(":", $header, 2);
                    if (!isset($split[1])) {
                        return $this->fail(new ClientException);
                    }
                    $headers[strtolower($split[0])] = trim($split[1]);
                }

                if (!preg_match('#^\s*form-data(?:\s*;\s*(?:name\s*=\s*"([^"]+)"|filename\s*=\s*"([^"]+)"))+\s*$#', $headers["content-disposition"] ?? "", $m) || !isset($m[1])) {
                    return $this->fail(new ClientException);
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
                $dataPromisor = $this->initField($field, $metadata);

                $buf = substr($buf, $end + 4);
                $off = 0;
                
                while (($end = strpos($buf, $sep, $off)) === false) {
                    if (!yield $this->body->valid()) {
                        $e = new ClientException;
                        $dataPromisor->fail($e);
                        return $this->fail($e);
                    }
                    
                    $buf .= $this->body->consume();
                    if (\strlen($buf) > \strlen($sep)) {
                        $off = \strlen($buf) - \strlen($sep);
                        $data = substr($buf, 0, $off);
                        if ($this->updateFieldSize($field, $data)) {
                            $dataPromisor->fail(new ClientSizeException);
                            return;
                        }
                        $dataPromisor->update($data);
                        $buf = substr($buf, $off);
                    }
                }

                $data = substr($buf, 0, $end);
                if ($this->updateFieldSize($field, $data)) {
                    $dataPromisor->fail(new ClientSizeException);
                    return;
                }
                $dataPromisor->update($data);
                $dataPromisor->succeed();
                $off = $end + \strlen($sep);

                while (\strlen($buf) < 4) {
                    if (!yield $this->body->valid()) {
                        return $this->fail(new ClientException);
                    }
                    $buf .= $this->body->consume();
                }
            }
        } else {
            $field = null;
            while (yield $this->body->valid()) {
                $new = $this->body->consume();

                if ($new[0] === "&") {
                    if ($field !== null) {
                        if ($noData || $buf != "") {
                            $data = urldecode($buf);
                            if ($this->updateFieldSize($field, $data)) {
                                $dataPromisor->fail(new ClientSizeException);
                                return;
                            }
                            $dataPromisor->update($data);
                            $buf = "";
                        }
                        $field = null;
                        $dataPromisor->succeed();
                    } elseif ($buf != "") {
                        if (!$dataPromisor = $this->initField(urldecode($buf))) {
                            return;
                        }
                        $dataPromisor->update("");
                        $dataPromisor->succeed();
                        $buf = "";
                    }
                }

                $buf .= strtok($new, "&");
                if ($field !== null && ($new = strtok("&")) !== false) {
                    $data = urldecode($buf);
                    if ($this->updateFieldSize($field, $data)) {
                        $dataPromisor->fail(new ClientSizeException);
                        return;
                    }
                    $dataPromisor->update($data);
                    $dataPromisor->succeed();

                    $buf = $new;
                    $field = null;
                }

                while (($next = strtok("&")) !== false) {
                    $pair = explode("=", $buf, 2);
                    $key = urldecode($pair[0]);
                    if (!$dataPromisor = $this->initField($key)) {
                        return;
                    }
                    $data = urldecode($pair[1] ?? "");
                    if ($this->updateFieldSize($key, $data)) {
                        $dataPromisor->fail(new ClientSizeException);
                        return;
                    }
                    $dataPromisor->update($data);
                    $dataPromisor->succeed();

                    $buf = $next;
                }

                if ($field === null) {
                    if (($new = strstr($buf, "=", true)) !== false) {
                        $field = urldecode($new);
                        if (!$dataPromisor = $this->initField($field)) {
                            return;
                        }
                        $buf = substr($buf, \strlen($new) + 1);
                        $noData = true;
                    } elseif (\strlen($buf) > $this->maxFieldLen) {
                        return $this->fail();
                    }
                }

                if ($field !== null && $buf != "" && (\strlen($buf > 2) || $buf[0] !== "%")) {
                    if (\strlen($buf) > 1 ? false !== $percent = strrpos($buf, "%", -2) : !($percent = $buf[0] !== "%")) {
                        if ($percent) {
                            if ($this->updateFieldSize($field, $data)) {
                                return;
                            }
                            $dataPromisor->update(urldecode(substr($buf, 0, $percent)));
                            $buf = substr($buf, $percent);
                        }
                    } else {
                        $data = urldecode($buf);
                        if ($this->updateFieldSize($field, $data)) {
                            $dataPromisor->fail(new ClientSizeException);
                            return;
                        }
                        $dataPromisor->update($data);
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
                    $dataPromisor->update($data);
                }
                $dataPromisor->succeed();
                $field = null;
            } elseif ($buf) {
                $field = urldecode($buf);
                if (!$dataPromisor = $this->initField($field)) {
                    return;
                }
                $dataPromisor->update("");
                $dataPromisor->succeed();
            }
        }

        foreach ($this->bodyPromisors as $fieldArray) {
            foreach ($fieldArray as list($promisor, $metadata)) {
                $promisor->succeed();
                $metadata->succeed([]);
            }
        }
    }
}