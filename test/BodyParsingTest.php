<?php

namespace Aerys\Test;

use Aerys\Body;
use Aerys\Request;
use Amp\ByteStream\IteratorStream;
use Amp\Loop;
use Amp\Uri\Uri;
use PHPUnit\Framework\TestCase;

class BodyParsingTest extends TestCase {
    /**
     * @dataProvider requestBodies
     */
    public function testDecoding(string $header, string $data, array $fields, array $metadata) {
        $emitter = new \Amp\Emitter;

        $headers = [];
        $headers["content-type"] = [$header];
        $body = new Body(new IteratorStream($emitter->iterate()));

        $request = new Request("POST", new Uri("/"), $headers, $body);

        $emitter->emit($data);
        $emitter->complete();

        Loop::run(function () use ($request, &$result) {
            $parsedBody = yield \Aerys\parseBody($request);
            $result = $parsedBody->getAll();
        });

        $this->assertEquals($fields, $result["fields"]);
        $this->assertEquals($metadata, $result["metadata"]);
    }

    /**
     * @dataProvider requestBodies
     */
    public function testImmediateWatch(string $header, string $data, array $fields, array $metadata) {
        $emitter = new \Amp\Emitter;

        $headers = [];
        $headers["content-type"] = [$header];
        $body = new Body(new IteratorStream($emitter->iterate()));

        $emitter->emit($data);
        $emitter->complete();

        $request = new Request("POST", new Uri("/"), $headers, $body);

        Loop::run(function () use ($request, $fields, $metadata) {
            $fieldlist = $fields;

            $body = \Aerys\parseBody($request);

            while (($field = yield $body->fetch()) !== null) {
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
    public function testIncrementalWatch(string $header, string $data, array $fields, array $metadata) {
        $emitter = new \Amp\Emitter;

        $headers = [];
        $headers["content-type"] = [$header];
        $body = new Body(new IteratorStream($emitter->iterate()));

        $request = new Request("POST", new Uri("/"), $headers, $body);

        Loop::run(function () use ($emitter, $data, $request, $fields, $metadata) {
            $fieldlist = $fields;

            Loop::defer(function () use ($emitter, $data) {
                $emitter->emit($data);
                $emitter->complete();
            });

            $body = \Aerys\parseBody($request);
            while (($field = yield $body->fetch()) !== null) {
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
    public function testStream(string $header, string $data, array $fields) {
        $emitter = new \Amp\Emitter;

        $headers = [];
        $headers["content-type"] = [$header];
        $body = new Body(new IteratorStream($emitter->iterate()));

        $request = new Request("POST", new Uri("/"), $headers, $body);

        Loop::run(function () use ($emitter, $data, $request, $fields) {
            Loop::defer(function () use ($emitter, $data) {
                $emitter->emit($data);
                $emitter->complete();
            });

            $bodies = [];
            $body = \Aerys\parseBody($request);
            while (null !== $name = yield $body->fetch()) {
                $bodies[] = [$body->stream($name), \array_shift($fields[$name])];
            }

            foreach ($bodies as list($stream, $expected)) {
                $this->assertEquals($expected, yield $stream->buffer());
            }

            $result = (yield $body)->getAll();

            $this->assertEquals([], $result["fields"]);
        });
    }

    /**
     * @dataProvider requestBodies
     */
    public function testPartialStream(string $header, string $data, array $fields) {
        $emitter = new \Amp\Emitter;

        $headers = [];
        $headers["content-type"] = [$header];
        $body = new Body(new IteratorStream($emitter->iterate()));

        $request = new Request("POST", new Uri("/"), $headers, $body);

        Loop::run(function () use ($emitter, $data, $request, $fields) {
            $remaining = [];

            Loop::defer(function () use ($emitter, $data) {
                $emitter->emit($data);
                $emitter->complete();
            });

            $bodies = [];
            $body = \Aerys\parseBody($request);
            while (null !== $name = yield $body->fetch()) {
                if (isset($bodies[$name])) {
                    $remaining[$name][] = \array_shift($fields[$name]);
                    continue;
                }

                $bodies[$name] = [$body->stream($name), \array_shift($fields[$name])];
            }

            foreach ($bodies as list($stream, $expected)) {
                $this->assertEquals($expected, yield $stream->buffer());
            }

            $result = (yield $body)->getAll();

            $this->assertEquals($remaining, $result["fields"]);
        });
    }

    public function testOutOfOrderStream() {
        $data = "a=ba%66g&&&be=c&d=f%6&gh&j";

        $emitter = new \Amp\Emitter;

        $headers = [];
        $headers["content-type"] = ["application/x-www-form-urlencoded"];
        $body = new Body(new IteratorStream($emitter->iterate()));

        $request = new Request("POST", new Uri("/"), $headers, $body);

        $body = new \Aerys\BodyParser($request);
        // Purposely out of order of data arrival.
        $b = $body->stream("b");
        $d = $body->stream("d");
        $gh = $body->stream("gh");
        $j = $body->stream("j");
        $be = $body->stream("be");
        $a = $body->stream("a");

        Loop::run(function () use ($a, $b, $be, $d, $gh, $j, $data, $emitter) {
            Loop::defer(function () use ($data, $emitter) {
                for ($i = 0; $i < \strlen($data); $i++) {
                    $emitter->emit($data[$i]);
                }
                $emitter->complete();
            });
            $this->assertEquals("bafg", yield $a->buffer());
            $this->assertEquals("", yield $b->buffer()); // not existing
            $this->assertEquals("c", yield $be->buffer());
            $this->assertEquals("f%6", yield $d->buffer());
            $this->assertEquals("", yield $gh->buffer());
            $this->assertEquals("", yield $j->buffer());
        });
        $body->onResolve(function ($e) { print $e; });
    }

    public function requestBodies() {
        $return = [];

        // 0 --- basic request -------------------------------------------------------------------->

        $input = "a=b&c=d&e=f&e=g";

        $return[] = ["application/x-www-form-urlencoded", $input, ["a" => ["b"], "c" => ["d"], "e" => ["f", "g"]], []];

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
