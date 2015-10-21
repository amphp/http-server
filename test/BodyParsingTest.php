<?php

namespace Aerys\Test;

use Aerys\Body;
use Aerys\InternalRequest;
use Aerys\StandardRequest;

class BodyParsingTest extends \PHPUnit_Framework_TestCase {
    /**
     * @dataProvider requestBodies
     */
    function testDecoding($header, $data, $fields, $metadata) {
        $deferred = new \Amp\Deferred;
        $ireq = new InternalRequest;
        $ireq->headers["content-type"][0] = $header;
        $ireq->body = new Body($deferred->promise());

        $deferred->update($data);
        $deferred->succeed();

        \Aerys\parseBody(new StandardRequest($ireq))->when(function($e, $parsedBody) use (&$result) {
            $result = $parsedBody->getAll();
        });

        $this->assertEquals($fields, $result["fields"]);
        $this->assertEquals($metadata, $result["metadata"]);
    }

    function requestBodies() {
        $return = [];

        // 0 --- basic request -------------------------------------------------------------------->

        $input = "a=b&c=d&e[f]=g";

        $return[] = [null, $input, ["a" => "b", "c" => "d", "e" => ["f" => "g"]], []];

        // 1 --- basic multipart request ---------------------------------------------------------->

        $input = <<<MULTIPART
--unique-boundary-1\r
Content-Disposition: form-data; name="a"\r
\r
... Some text appears here ... including a blank line at the end
\r
--unique-boundary-1\r
Content-Disposition: form-data; name="b[]"\r
Content-type: text/plain; charset=US-ASCII\r
\r
And yet another field\r
--unique-boundary-1--\r\n
MULTIPART;

        $fields = [
            "a" => "... Some text appears here ... including a blank line at the end\n",
            "b" => [
                "And yet another field"
            ]
        ];

        $metadata = [
            "b" => [
                ["mime" => "text/plain; charset=US-ASCII"]
            ]
        ];

        $return[] = ["multipart/mixed; boundary=unique-boundary-1", $input, $fields, $metadata];

        // 2 --- multipart request with file ------------------------------------------------------>

        $input = <<<MULTIPART
-----------------------------9051914041544843365972754266\r
Content-Disposition: form-data; name="text[test]"\r
\r
text default\r
-----------------------------9051914041544843365972754266\r
Content-Disposition: form-data; name="file[1]"; filename="a.txt"\r
Content-Type: text/plain\r
\r
Content of a.txt.
\r
-----------------------------9051914041544843365972754266\r
Content-Disposition: form-data; name="file[2]"; filename="a.html"\r
Content-Type: text/html\r
\r
<!DOCTYPE html><title>Content of a.html.</title>
\r
-----------------------------9051914041544843365972754266--\r\n
MULTIPART;

        $fields = [
            "text" => [
                "test" => "text default"
            ],
            "file" => [
                1 => "Content of a.txt.\n",
                2 => "<!DOCTYPE html><title>Content of a.html.</title>\n"
            ]
        ];

        $metadata = [
            "file" => [
                1 => ["mime" => "text/plain", "filename" => "a.txt"],
                2 => ["mime" => "text/html", "filename" => "a.html"]
            ]
        ];

        $return[] = ["multipart/form-data; boundary=---------------------------9051914041544843365972754266", $input, $fields, $metadata];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }
}