<?php

namespace Amp\Http\Server\Test;

use Amp\ByteStream\InMemoryStream;
use Amp\Http\Server\RequestBody;
use Amp\PHPUnit\AsyncTestCase;

class RequestBodyTest extends AsyncTestCase
{
    public function testIncreaseWithoutCallback(): \Generator
    {
        $body = new RequestBody(new InMemoryStream("foobar"));
        $body->increaseSizeLimit(1);
        $this->assertSame("foobar", yield $body->buffer());
    }
}
