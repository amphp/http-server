<?php

namespace Aerys\Test;

use Aerys\Body;
use Aerys\Client;
use Aerys\InternalRequest;
use Aerys\Options;
use Aerys\StandardRequest;
use Amp\ByteStream\IteratorStream;
use Amp\Loop;
use PHPUnit\Framework\TestCase;

class BodyParsingTest extends TestCase {
    /**
     * @dataProvider requestBodies
     */
    public function testDecoding($header, $data, $fields, $metadata) {
        $emitter = new \Amp\Emitter;
        $ireq = new InternalRequest;
        $ireq->headers["content-type"][0] = $header;
        $ireq->body = new Body(new IteratorStream($emitter->iterate()));
        $ireq->client = new Client;
        $ireq->client->options = new Options;

        $emitter->emit($data);
        $emitter->complete();

        Loop::run(function () use ($ireq, &$result) {
            $parsedBody = yield \Aerys\parseBody(new StandardRequest($ireq));
            $result = $parsedBody->getAll();
        });

        $this->assertEquals($fields, $result["fields"]);
        $this->assertEquals($metadata, $result["metadata"]);
    }

    /**
     * @dataProvider requestBodies
     */
    public function testImmediateWatch($header, $data, $fields, $metadata) {
        $emitter = new \Amp\Emitter;
        $ireq = new InternalRequest;
        $ireq->headers["content-type"][0] = $header;
        $ireq->body = new Body(new IteratorStream($emitter->iterate()));
        $ireq->client = new Client;
        $ireq->client->options = new Options;

        $emitter->emit($data);
        $emitter->complete();

        Loop::run(function () use ($ireq, $fields, $metadata) {
            $fieldlist = $fields;

            $body = \Aerys\parseBody(new StandardRequest($ireq));

            while (($field = yield $body->read()) !== null) {
                $this->assertArrayHasKey($field, $fieldlist);
                array_pop($fieldlist[$field]);
            }
            $result = (yield $body)->getAll();

            $this->assertEquals(count($fieldlist), count($fieldlist, \COUNT_RECURSIVE));
            $this->assertEquals($fields, $result["fields"]);
            $this->assertEquals($metadata, $result["metadata"]);
        });
    }

    /**
     * @dataProvider requestBodies
     */
    public function testIncrementalWatch($header, $data, $fields, $metadata) {
        $emitter = new \Amp\Emitter;
        $ireq = new InternalRequest;
        $ireq->headers["content-type"][0] = $header;
        $ireq->body = new Body(new IteratorStream($emitter->iterate()));
        $ireq->client = new Client;
        $ireq->client->options = new Options;

        Loop::run(function () use ($emitter, $data, $ireq, $fields, $metadata) {
            $fieldlist = $fields;

            Loop::defer(function () use ($emitter, $data) {
                $emitter->emit($data);
                $emitter->complete();
            });

            $body = \Aerys\parseBody(new StandardRequest($ireq));
            while (($field = yield $body->read()) !== null) {
                $this->assertArrayHasKey($field, $fieldlist);
                array_pop($fieldlist[$field]);
            }
            $result = (yield $body)->getAll();

            $this->assertEquals(count($fieldlist), count($fieldlist, \COUNT_RECURSIVE));
            $this->assertEquals($fields, $result["fields"]);
            $this->assertEquals($metadata, $result["metadata"]);
        });
    }

    public function testNew() {
        $header = null;
        $data = "a=ba%66g&&&be=c&d=f%6&gh&j";

        $emitter = new \Amp\Emitter;
        $ireq = new InternalRequest;
        $ireq->headers["content-type"][0] = $header;
        $ireq->body = new Body(new IteratorStream($emitter->iterate()));
        $ireq->client = new Client;
        $ireq->client->options = new Options;

        $body = new \Aerys\BodyParser(new StandardRequest($ireq));
        $a = $body->write("a");
        $b = $body->write("b");
        $be = $body->write("be");
        $d = $body->write("d");
        $gh = $body->write("gh");
        $j = $body->write("j");

        Loop::run(function () use ($a, $b, $be, $d, $gh, $j, $data, $emitter) {
            Loop::defer(function () use ($data, $emitter) {
                for ($i = 0; $i < \strlen($data); $i++) {
                    $emitter->emit($data[$i]);
                }
                $emitter->complete();
            });
            $this->assertEquals("bafg", yield $a);
            $this->assertEquals("", yield $b); // not existing
            $this->assertEquals("c", yield $be);
            $this->assertEquals("f%6", yield $d);
            $this->assertEquals("", yield $gh);
            $this->assertEquals("", yield $j);
        });
        $body->onResolve(function ($e) { print $e; });
    }

    public function requestBodies() {
        $return = [];

        // 0 --- basic request -------------------------------------------------------------------->

        $input = "a=b&c=d&e=f&e=g";

        $return[] = [null, $input, ["a" => ["b"], "c" => ["d"], "e" => ["f", "g"]], []];

        // 1 --- basic multipart request ---------------------------------------------------------->

        $input = <<<MULTIPART
--unique-boundary-1\r
Content-Disposition: form-data; name="a"\r
\r
... Some text appears here ... including a blank line at the end
\r
--unique-boundary-1\r
Content-Disposition: form-data; name="b"\r
\r
And yet another field\r
--unique-boundary-1\r
Content-Disposition: form-data; name="b"\r
Content-type: text/plain; charset=US-ASCII\r
\r
Hey, number b2!\r
--unique-boundary-1--\r\n
MULTIPART;

        $fields = [
            "a" => ["... Some text appears here ... including a blank line at the end\n"],
            "b" => [
                "And yet another field",
                "Hey, number b2!",
            ]
        ];

        $metadata = [
            "b" => [
                1 => ["mime" => "text/plain; charset=US-ASCII"]
            ]
        ];

        $return[] = ["multipart/mixed; boundary=unique-boundary-1", $input, $fields, $metadata];

        // 2 --- multipart request with file ------------------------------------------------------>

        $input = <<<MULTIPART
-----------------------------9051914041544843365972754266\r
Content-Disposition: form-data; name="text"\r
\r
text default\r
-----------------------------9051914041544843365972754266\r
Content-Disposition: form-data; name="file"; filename="a.txt"\r
Content-Type: text/plain\r
\r
Content of a.txt.
\r
-----------------------------9051914041544843365972754266\r
Content-Disposition: form-data; name="file"; filename="a.html"\r
Content-Type: text/html\r
\r
<!DOCTYPE html><title>Content of a.html.</title>
\r
-----------------------------9051914041544843365972754266--\r\n
MULTIPART;

        $fields = [
            "text" => [
                "text default"
            ],
            "file" => [
                "Content of a.txt.\n",
                "<!DOCTYPE html><title>Content of a.html.</title>\n"
            ]
        ];

        $metadata = [
            "file" => [
                ["mime" => "text/plain", "filename" => "a.txt"],
                ["mime" => "text/html", "filename" => "a.html"]
            ]
        ];

        $return[] = ["multipart/form-data; boundary=---------------------------9051914041544843365972754266", $input, $fields, $metadata];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }
}
