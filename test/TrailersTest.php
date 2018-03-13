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
}
