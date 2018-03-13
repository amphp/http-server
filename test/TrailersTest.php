<?php

namespace Amp\Http\Server\Test;

use Amp\Http\Server\Trailers;
use PHPUnit\Framework\TestCase;

class TrailersTest extends TestCase {
    public function testMessageHasHeader() {
        $trailers = new Trailers(['fooHeader' => 'barValue']);
        $this->assertTrue($trailers->hasHeader('fooHeader'));
    }
}
