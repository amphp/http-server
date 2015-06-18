<?php

namespace Aerys\Test;

use Aerys\FilterException;
use Aerys\InternalRequest;

class responseFilterTest extends \PHPUnit_Framework_TestCase {
    private function getFilter(array $filters, InternalRequest $ireq = null) {
        $ireq = $ireq ?: new InternalRequest;
        $f = new \ReflectionMethod('\Aerys\Server', 'responseFilter');
        $f->setAccessible(true);

        return $f->invoke(null, $filters, $ireq);
    }

    public function testEmptyFilters() {
        $filter = $this->getFilter([]);
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
        $filter = $this->getFilter([function() {
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
            $filter = $this->getFilter([function() {
                $headers = yield;
                yield 42;
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->fail("Expected filter exception was not thrown");
        } catch (FilterException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf("DomainException", $e);
            if (0 !== strpos($e->getMessage(), "Filter error; header array required but integer yielded")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }

    public function testFilterThrowsIfHeadersNotReturnedWhenFlushing() {
        try {
            $filter = $this->getFilter([function() {
                while(1) yield;
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->assertNull($result);
            $filter->send(false);
            $this->fail("Expected filter exception was not thrown");
        } catch (FilterException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf("DomainException", $e);
            if (0 !== strpos($e->getMessage(), "Filter error; header array required from FLUSH signal")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }

    public function testFilterThrowsIfHeadersNotReturnedWhenEnding() {
        try {
            $filter = $this->getFilter([function() {
                while(1) yield;
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->assertNull($result);
            $filter->send(null);
            $this->fail("Expected filter exception was not thrown");
        } catch (FilterException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf("DomainException", $e);
            if (0 !== strpos($e->getMessage(), "Filter error; header array required from END signal")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }

    public function testFilterThrowsIfDetchedBeforeHeaderReturn() {
        try {
            $filter = $this->getFilter([function() {
                yield;
                yield;
                return;
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->assertNull($result);
            $filter->send("foo");
            $filter->send(null);
            $this->fail("Expected filter exception was not thrown");
        } catch (FilterException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf("DomainException", $e);
            if (0 !== strpos($e->getMessage(), "Filter error; cannot detach without yielding/returning headers")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }

    public function testBufferedFilterHeaderYield() {
        $filter = $this->getFilter([function() {
            $headers = yield;
            $buffer = "";
            while (($part = yield) !== null) {
                $buffer .= $part;
            }

            yield $headers;
            return $buffer;
        }]);
        $filter->current();
        $result = $filter->send([":status" => 200]);
        $this->assertNull($result);
        $this->assertNull($filter->send("foo"));
        $this->assertNull($filter->send("bar"));
        $this->assertNull($filter->send("baz"));
        $this->assertSame([":status" => 200], $filter->send(null));
        $this->assertNull($filter->send(null));
        $this->assertSame("foobarbaz", $filter->getReturn());
    }

    public function testBufferedFilterHeaderYieldThrowsIfNotAnArray() {
        try {
            $filter = $this->getFilter([function() {
                $headers = yield;
                $buffer = "";
                while (($part = yield) !== null) {
                    $buffer .= $part;
                }

                yield 42;
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->assertNull($result);
            $this->assertNull($filter->send("foo"));
            $this->assertNull($filter->send("bar"));
            $this->assertNull($filter->send("baz"));
            $filter->send(null);
            $this->fail("Expected filter exception was not thrown");
        } catch (FilterException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf("DomainException", $e);
            if (0 !== strpos($e->getMessage(), "Filter error; header array required but integer yielded")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }
}
