<?php declare(strict_types=1);

namespace Amp\Http\Server\Test;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\RequestBody;
use Amp\PHPUnit\AsyncTestCase;

class RequestBodyTest extends AsyncTestCase
{
    public function testIncreaseWithoutCallback(): void
    {
        $body = new RequestBody(new ReadableBuffer("foobar"));
        $body->increaseSizeLimit(1);
        $this->assertSame("foobar", (string) $body);
    }
}
