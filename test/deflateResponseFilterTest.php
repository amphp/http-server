<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\Options;
use PHPUnit\Framework\TestCase;

class deflateResponseFilterTest extends TestCase {
    public function testNoDeflate() {
        $longData = str_repeat("10", 1000);
        $shortData = str_repeat("10", 10);
        $ireq = new Internal\Request;
        $ireq->client = new Client;
        $ireq->client->options = new Options;
        $ireq->client->options->deflateContentTypes = "#^text/.*$#";

        $try = function ($header, $data) use ($ireq) {
            $deflate = \Aerys\deflateResponseFilter($ireq);
            $newHeader = $deflate->send($header);
            $result = "";
            if ($deflate->valid()) {
                if ($newHeader === null) {
                    $newHeader = $deflate->send($data);
                } else {
                    $result .= $deflate->send($data);
                }
            } else {
                $newHeader = $deflate->getReturn() ?? $header;
                $this->assertEquals($header, $newHeader);
                return;
            }
            if ($deflate->valid()) {
                if ($newHeader === null) {
                    $newHeader = $deflate->send(null);
                }
                if ($deflate->valid()) {
                    $this->assertNull($deflate->send(null));
                    $this->assertFalse($deflate->valid());
                }
            }
            if ($newHeader === null) {
                $newHeader = $deflate->getReturn();
            } else {
                $result .= $deflate->getReturn();
                $this->assertEquals($data, $result);
            }
            $this->assertEquals($header, $newHeader);
        };

        $try([], $longData);

        $ireq->headers["accept-encoding"][0] = "compress";
        $try(["content-type" => ["text/html"]], $longData);

        $ireq->headers["accept-encoding"][0] = "gzip";
        $try([], $longData);
        $try(["content-type" => ["application/x-octet-stream"]], $longData);
        $try(["content-type" => ["application/x-octet-stream"]], $longData); // test cache
        $try(["content-type" => ["text/html"]], $shortData);
        $try(["content-type" => ["text/html"], "content-length" => [20]], $shortData);
    }

    public function testSuccessfulDeflate() {
        $try = function ($header = []) {
            $ireq = new Internal\Request;
            $ireq->client = new Client;
            $ireq->client->options = new Options;
            $ireq->headers["accept-encoding"] = ["compress", "identity, gzip"];
            $header += ["content-type" => ["text/html; charset=UTF-8"], ":status" => 200];

            $filter = \Aerys\responseFilter(['Aerys\deflateResponseFilter'], $ireq);
            $newHeader = $filter->send($header);
            $deflate = "";
            $body = "";
            $ex = false;
            try {
                while (1) {
                    $ret = $filter->send($yield = yield);
                    if ($newHeader === null) {
                        $newHeader = $ret;
                    } else {
                        $deflate .= $ret;
                    }
                    $body .= $yield;
                }
            } catch (\Throwable $e) {
            } finally {
                if (isset($e)) {
                    throw $e;
                }
                $ret = $filter->send(null);
                if ($newHeader === null) {
                    $newHeader = $ret;
                } else {
                    $deflate .= $ret;
                }
                $deflate .= $filter->getReturn();
                $this->assertEquals($body, zlib_decode($deflate));
            }
        };

        $data = str_repeat("10", 1000);

        $deflate = $try();
        $deflate->send($data);

        $deflate = $try();
        for ($i = 0; $i < \strlen($data); $i++) {
            $deflate->send($data[$i]);
        }

        $deflate = $try();
        $deflate->send(substr($data, 0, 1000));
        for ($i = 1000; $i < \strlen($data); $i++) {
            $deflate->send($data[$i]);
            $deflate->send(false);
        }
    }
}
