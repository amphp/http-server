<?php

namespace Aerys\Test;

use function Aerys\responseFilter;
use Aerys\CodecException;

class responseFilterTest extends \PHPUnit_Framework_TestCase {
    public function testEmptyFilters() {
        $filter = responseFilter([]);
        $filter->current();
        $headers = [":status" => 200];
        $result = $filter->send($headers);
        $this->assertSame($headers, $result);

        $body = "1";
        $result = $filter->send($body);
        $this->assertSame($body, $result);

        $body = "2";
        $result = $filter->send($body);
        $this->assertSame($body, $result);

        $result = $filter->send(null);
        $this->assertNull($result);
    }

    public function testSingleHeaderArrayFilter() {
        $filter = responseFilter([function() {
            $headers = yield;
            yield [":status" => 404];
        }]);

        $filter->current();
        $result = $filter->send([":status" => 200]);
        $this->assertSame([":status" => 404], $result);

        $body = "1";
        $result = $filter->send($body);
        $this->assertSame($body, $result);

        $body = "2";
        $result = $filter->send($body);
        $this->assertSame($body, $result);

        $result = $filter->send(null);
        $this->assertNull($result);
    }

    public function testBadHeaderTypeThrows() {
        try {
            $filter = responseFilter([function() {
                $headers = yield;
                yield 42;
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->fail("Expected filter exception was not thrown");
        } catch (CodecException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf("DomainException", $e);
            if (0 !== strpos($e->getMessage(), "Codec error; header array required but integer yielded")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }

    public function testFilterThrowsIfHeadersNotReturnedWhenFlushing() {
        try {
            $filter = responseFilter([function() {
                while(1) yield;
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->assertNull($result);
            $filter->send(false);
            $this->fail("Expected filter exception was not thrown");
        } catch (CodecException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf("DomainException", $e);
            if (0 !== strpos($e->getMessage(), "Codec error; header array required from FLUSH signal")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }

    public function testFilterThrowsIfHeadersNotReturnedWhenEnding() {
        try {
            $filter = responseFilter([function() {
                while(1) yield;
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->assertNull($result);
            $filter->send(null);
            $this->fail("Expected filter exception was not thrown");
        } catch (CodecException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf("DomainException", $e);
            if (0 !== strpos($e->getMessage(), "Codec error; header array required from END signal")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }
}
