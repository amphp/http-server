<?php

namespace Aerys\Test;

use Aerys\HPack;

class HPackTest extends \PHPUnit_Framework_TestCase {
    /**
     * @dataProvider provideTestCases
     */
    function testHPack($cases) {
        $hpack = new HPack;
        foreach ($cases as $i => list($input, $output)) {
            $result = $hpack->decode($input);
            $this->assertEquals($output, $result, "Failure on testcase #$i");
        }
    }

    function provideTestCases() {
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
}