<?php

namespace Aerys\Test;

use Aerys\FilterException;
use Aerys\InternalRequest;
use PHPUnit\Framework\TestCase;
use function Aerys\responseFilter;

class responseFilterTest extends TestCase {
    private function getFilter(array $filters, InternalRequest $ireq = null) {
        $ireq = $ireq ?: new InternalRequest;
        return responseFilter($filters, $ireq);
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
        $filter = $this->getFilter([function () {
            $headers = yield;
            yield [":status" => 404];
        }]);

        $filter->current();
        $result = $filter->send([":status" => 200]);
        $this->assertSame([":status" => 404], $result);

        $body = "1";
        $result = $filter->send($body);
        $this->assertNull($result);

        $body = "2";
        $result = $filter->send($body);
        $this->assertSame($body, $result);

        $result = $filter->send(null);
        $this->assertNull($result);
    }

    public function testBadHeaderTypeThrows() {
        try {
            $filter = $this->getFilter([function () {
                $headers = yield;
                yield 42;
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->fail("Expected filter exception was not thrown");
        } catch (FilterException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf('Amp\InvalidYieldError', $e);
            if (0 !== strpos($e->getMessage(), "Filter error; header array required but integer yielded")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }

    public function testFilterThrowsIfHeadersNotReturnedWhenFlushing() {
        try {
            $filter = $this->getFilter([function () {
                while (1) {
                    yield;
                }
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->assertNull($result);
            $filter->send(false);
            $this->fail("Expected filter exception was not thrown");
        } catch (FilterException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf('Amp\InvalidYieldError', $e);
            if (0 !== strpos($e->getMessage(), "Filter error; header array required from FLUSH (false) signal")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }

    public function testFilterThrowsIfHeadersNotReturnedWhenEnding() {
        try {
            $filter = $this->getFilter([function () {
                while (1) {
                    yield;
                }
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->assertNull($result);
            $filter->send(null);
            $this->fail("Expected filter exception was not thrown");
        } catch (FilterException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf('Amp\InvalidYieldError', $e);
            if (0 !== strpos($e->getMessage(), "Filter error; header array required from END (null) signal")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }

    public function testFilterThrowsIfDetchedBeforeHeaderReturn() {
        try {
            $filter = $this->getFilter([function () {
                yield;
                yield;
            }]);
            $filter->current();
            $result = $filter->send([":status" => 200]);
            $this->assertNull($result);
            $filter->send("foo");
            $filter->send(null);
            $this->fail("Expected filter exception was not thrown");
        } catch (FilterException $e) {
            $e = $e->getPrevious();
            $this->assertInstanceOf('Amp\InvalidYieldError', $e);
            if (0 !== strpos($e->getMessage(), "Filter error; cannot detach without yielding/returning headers")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }

    public function testBufferedFilterHeaderYield() {
        $filter = $this->getFilter([function () {
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

    public function testNestedBufferedFilterHeaderYield() {
        $filter = $this->getFilter([function () {
            $headers = yield;
            $this->assertSame("foo", yield $headers);
            $this->assertSame("bar", yield "foo");
            $this->assertNull(yield "bar");
        }, function () {
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
        $this->assertSame([":status" => 200], $filter->send(null));
        $this->assertNull($filter->send(null));
        $this->assertSame("foobar", $filter->getReturn());
    }

    public function testFlushBufferedFilterHeaderYield() {
        $filter = $this->getFilter([function () {
            $headers = yield;
            $buffer = "";
            while (($part = yield) !== null) {
                if ($part === false) {
                    if ($headers) {
                        $buffer .= yield $headers;
                        $headers = null;
                    } else {
                        $buffer = yield $buffer;
                    }
                }
                $buffer .= $part;
            }

            return $buffer;
        }]);
        $filter->current();
        $result = $filter->send([":status" => 200]);
        $this->assertNull($result);
        $this->assertNull($filter->send("foo"));
        $this->assertSame([":status" => 200], $filter->send(false));
        $this->assertNull($filter->send("bar"));
        $this->assertSame("foobar", $filter->send(false));
        $this->assertNull($filter->send("baz"));
        $this->assertNull($filter->send(null));
        $this->assertSame("baz", $filter->getReturn());
    }

    public function testDoubleFlushWithHeader() {
        $filter = $this->getFilter([function () {
            $headers = yield;
            $this->assertInternalType('array', $headers);

            $data = yield;
            $this->assertEquals("stream", $data);

            $flush = yield;
            $this->assertFalse($flush);
            $flush = yield $headers;
            $this->assertFalse($flush);

            $end = yield $data;
            $this->assertEquals("end", $end);
            yield $end;

            $this->assertNull(yield);
        }]);
        $filter->current();
        $this->assertNull($filter->send([":status" => 200]));
        $this->assertNull($filter->send("stream"));
        $this->assertSame([":status" => 200], $filter->send(false));
        $this->assertSame("stream", $filter->send(false));
        $this->assertEquals("end", $filter->send("end"));
        $this->assertNull($filter->send(null));
        $this->assertNull($filter->getReturn());
    }

    public function testDelayedHeaderWithTwoFilters() {
        $filter = $this->getFilter([function () {
            $headers = yield;
            $data = yield;
            yield;
            yield $headers;
            return $data;
        }, function () {
            $headers = yield;
            return $headers;
        }]);
        $filter->current();
        $this->assertNull($filter->send([":status" => 200]));
        $this->assertNull($filter->send("stream"));
        $this->assertSame([":status" => 200], $filter->send(null));
        $this->assertNull($filter->send(null));
        $this->assertSame("stream", $filter->getReturn());
    }

    public function testBufferedFilterHeaderYieldThrowsIfNotAnArray() {
        try {
            $filter = $this->getFilter([function () {
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
            $this->assertInstanceOf('Amp\InvalidYieldError', $e);
            if (0 !== strpos($e->getMessage(), "Filter error; header array required but integer yielded")) {
                $this->fail("Filter exception message differed from expected");
            }
        }
    }
}
