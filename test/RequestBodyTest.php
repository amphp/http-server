<?php

namespace Amp\Http\Server\Test;

use Amp\ByteStream\InMemoryStream;
use Amp\Http\Server\RequestBody;
use Amp\PHPUnit\TestCase;
use Amp\Promise;

class RequestBodyTest extends TestCase {
    public function testIncreaseWithoutCallback() {
        $body = new RequestBody(new InMemoryStream("foobar"));
        $body->increaseSizeLimit(1);
        $this->assertSame("foobar", Promise\wait($body->buffer()));
    }
}
