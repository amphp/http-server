<?php

namespace Amp\Http\Server\Test;

use Amp\Http\Server\Trailers;
use PHPUnit\Framework\TestCase;

class TrailersTest extends TestCase {
    public function testMessageHasHeader() {
        $trailers = new Trailers(['fooHeader' => 'barValue']);
        $this->assertTrue($trailers->hasHeader('fooHeader'));
    }

    public function testHasHeaderReturnsFalseForEmptyArrayValue() {
        $trailers = new Trailers(['fooHeader' => []]);
        $this->assertFalse($trailers->hasHeader('fooHeader'));
    }

    public function testSetHeaderReturnsFalseForEmptyArrayValue() {
        $trailers = new Trailers([]);
        $trailers->setHeader('fooHeader', []);
        $this->assertFalse($trailers->hasHeader('fooHeader'));
    }

    public function testAddHeaderReturnsFalseForEmptyArrayValue() {
        $trailers = new Trailers([]);
        $trailers->addHeader('fooHeader', []);
        $this->assertFalse($trailers->hasHeader('fooHeader'));
    }

    public function testAddHeaderReturnsTrueForEmptyArrayValueIfExisted() {
        $trailers = new Trailers(['fooHeader' => 'foo']);
        $trailers->addHeader('fooHeader', []);
        $this->assertTrue($trailers->hasHeader('fooHeader'));
    }

    public function testAddHeaderWithNonExistingStringValue() {
        $trailers = new Trailers([]);
        $trailers->addHeader('fooHeader', 'bar');
        $this->assertSame('bar', $trailers->getHeader('fooHeader'));
    }

    public function testAddHeaderWithExistingValue() {
        $trailers = new Trailers(['fooHeader' => 'foo']);
        $trailers->addHeader('fooHeader', 'bar');
        $this->assertSame(['fooheader' => ['foo', 'bar']], $trailers->getHeaders());
    }

    public function testSetHeaderDoesNotKeepStringKeys() {
        $trailers = new Trailers([]);
        $trailers->setHeader('fooHeader', ['stringKey' => 'bazValue']);
        $this->assertSame(['fooheader' => ['bazValue']], $trailers->getHeaders());
    }

    public function testAddHeaderDoesNotKeepStringKeys() {
        $trailers = new Trailers([]);
        $trailers->addHeader('fooHeader', ['stringKey' => 'barValue']);
        $this->assertSame(['fooheader' => ['barValue']], $trailers->getHeaders());
    }

    public function testSetHeadersIsAtomic() {
        if (\ini_get('zend.assertions') !== '1' || !\ini_get('assert.exception')) {
            $this->markTestSkipped('Assertions need to be enabled and throw for this test.');
        }

        $trailers = new Trailers([]);

        try {
            $trailers->setHeaders([
                'x' => 'y',
                'foo' => "x: test\r\n",
            ]);

            $this->fail('Expected an exception.');
        } catch (\AssertionError $e) {
            $this->assertFalse($trailers->hasHeader('x'));
            $this->assertFalse($trailers->hasHeader('foo'));
        }
    }
}
