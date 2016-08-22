<?php declare(strict_types = 1);

namespace Aerys\Test;

use Aerys\Body;
use Aerys\Client;
use Aerys\InternalRequest;
use Aerys\Options;
use Aerys\StandardRequest;

class BodyParsingTest extends \PHPUnit_Framework_TestCase {
    /**
     * @dataProvider requestBodies
     */
    function testDecoding($header, $data, $fields, $metadata) {
        $postponed = new \Amp\Postponed;
        $ireq = new InternalRequest;
        $ireq->headers["content-type"][0] = $header;
        $ireq->body = new Body($postponed->getObservable());
        $ireq->client = new Client;
        $ireq->client->options = new Options;

        $postponed->emit($data);
        $postponed->resolve();

        \Amp\execute(function() use ($ireq, &$result) {
            yield \Aerys\parseBody(new StandardRequest($ireq))->when(function ($e, $parsedBody) use (&$result) {
                $result = $parsedBody->getAll();
            });
        });

        $this->assertEquals($fields, $result["fields"]);
        $this->assertEquals($metadata, $result["metadata"]);
    }
    
    /**
     * @dataProvider requestBodies
     */
    function testImmediateWatch($header, $data, $fields, $metadata) {
        $postponed = new \Amp\Postponed;
        $ireq = new InternalRequest;
        $ireq->headers["content-type"][0] = $header;
        $ireq->body = new Body($postponed->getObservable());
        $ireq->client = new Client;
        $ireq->client->options = new Options;

        $postponed->emit($data);
        $postponed->resolve();

        \Amp\execute(function() use ($ireq, $fields, $metadata) {
            $fieldlist = $fields;

            $body = \Aerys\parseBody(new StandardRequest($ireq));
            $body->subscribe(function ($data) use (&$fieldlist) {
                $this->assertArrayHasKey($data, $fieldlist);
                array_pop($fieldlist[$data]);
            });
            $body->when(function ($e, $parsedBody) use (&$result) {
                $result = $parsedBody->getAll();
                $this->assertNull($e);
            });
            yield $body;

            $this->assertEquals(count($fieldlist), count($fieldlist, \COUNT_RECURSIVE));
            $this->assertEquals($fields, $result["fields"]);
            $this->assertEquals($metadata, $result["metadata"]);
        });
    }

    /**
     * @dataProvider requestBodies
     */
    function testIncrementalWatch($header, $data, $fields, $metadata) {
        $postponed = new \Amp\Postponed;
        $ireq = new InternalRequest;
        $ireq->headers["content-type"][0] = $header;
        $ireq->body = new Body($postponed->getObservable());
        $ireq->client = new Client;
        $ireq->client->options = new Options;

        \Amp\execute(function() use ($postponed, $data, $ireq, $fields, $metadata) {
            $fieldlist = $fields;
            
            \Amp\defer(function() use ($postponed, $data) {
                $postponed->emit($data);
                $postponed->resolve();
            });

            $body = \Aerys\parseBody(new StandardRequest($ireq));
            $body->subscribe(function ($data) use (&$fieldlist) {
                $this->assertArrayHasKey($data, $fieldlist);
                array_pop($fieldlist[$data]);
            });
            $body->when(function ($e, $parsedBody) use (&$result) {
                $this->assertNull($e);
                $result = $parsedBody->getAll();
            });
            yield $body;

            $this->assertEquals(count($fieldlist), count($fieldlist, \COUNT_RECURSIVE));
            $this->assertEquals($fields, $result["fields"]);
            $this->assertEquals($metadata, $result["metadata"]);
        });
    }

    function testNew() {
        $header = null;
        $data = "a=ba%66g&&&be=c&d=f%6&gh&j";
        
        $postponed = new \Amp\Postponed;
        $ireq = new InternalRequest;
        $ireq->headers["content-type"][0] = $header;
        $ireq->body = new Body($postponed->getObservable());
        $ireq->client = new Client;
        $ireq->client->options = new Options;

        $body = new \Aerys\BodyParser(new StandardRequest($ireq));
        $a = $body->stream("a");
        $b = $body->stream("b");
        $be = $body->stream("be");
        $d = $body->stream("d");
        $gh = $body->stream("gh");
        $j = $body->stream("j");


        \Amp\execute(function() use ($a, $b, $be, $d, $gh, $j, $data, $postponed) {
            \Amp\defer(function() use ($data, $postponed) {
                for ($i = 0; $i < \strlen($data); $i++) {
                    $postponed->emit($data[$i]);
                }
                $postponed->resolve();
            });
            $this->assertEquals("bafg", yield $a);
            $this->assertEquals("", yield $b); // not existing
            $this->assertEquals("c", yield $be);
            $this->assertEquals("f%6", yield $d);
            $this->assertEquals("", yield $gh);
            $this->assertEquals("", yield $j);
        });
        $body->when(function($e) { print $e; });
    }

    function requestBodies() {
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