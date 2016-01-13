<?php

namespace Aerys\Test;

use Aerys\HPack;

class HPackTest extends \PHPUnit_Framework_TestCase {
    /**
     * @dataProvider provideDecodeCases
     */
    function testDecode($cases) {
        $hpack = new HPack;
        foreach ($cases as $i => list($input, $output)) {
            $result = $hpack->decode($input);
            $this->assertEquals($output, $result, "Failure on testcase #$i");
        }
    }

    function provideDecodeCases() {
        $root = __DIR__."/../vendor/http2jp/hpack-test-case";
        $paths = glob("$root/*/*.json");
        foreach ($paths as $path) {
            if (basename(dirname($path)) == "raw-data") {
                continue;
            }

            $data = json_decode(file_get_contents($path));
            $cases = [];
            foreach ($data->cases as $case) {
                foreach ($case->headers as &$header) {
                    $header = (array) $header;
                    $header = [key($header), current($header)];
                }
                $cases[$case->seqno] = [hex2bin($case->wire), $case->headers];
            }
            yield basename($path).": $data->description" => [$cases];
        }
    }

    /**
     * @depends testDecode
     * @dataProvider provideEncodeCases
     */
    function testEncode($cases) {
        foreach ($cases as $i => list($input, $output)) {
            $hpack = new HPack;
            $encoded = $hpack->encode($input);
            $hpack = new HPack;
            $decoded = $hpack->decode($encoded);
            sort($output);
            sort($decoded);
            $this->assertEquals($output, $decoded, "Failure on testcase #$i (standalone)");
        }

        // Ensure that usage of dynamic table works as expected
        $encHpack = new HPack;
        $decHpack = new HPack;
        foreach ($cases as $i => list($input, $output)) {
            $encoded = $encHpack->encode($input);
            $decoded = $decHpack->decode($encoded);
            sort($output);
            sort($decoded);
            $this->assertEquals($output, $decoded, "Failure on testcase #$i (shared context)");
        }
    }

    function provideEncodeCases() {
        $root = __DIR__."/../vendor/http2jp/hpack-test-case";
        $paths = glob("$root/raw-data/*.json");
        foreach ($paths as $path) {
            $data = json_decode(file_get_contents($path));
            $cases = [];
            $i = 0;
            foreach ($data->cases as $case) {
                $headers = [];
                foreach ($case->headers as &$header) {
                    $header = (array) $header;
                    $header = [key($header), current($header)];
                    $headers[$header[0]][] = $header[1];
                }
                $cases[$case->seqno ?? $i] = [$headers, $case->headers];
                $i++;
            }
            yield basename($path) . (isset($data->description) ? ": $data->description" : "") => [$cases];
        }
    }

}