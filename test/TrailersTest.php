<?php declare(strict_types=1);

namespace Amp\Http\Server\Test;

use Amp\Future;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Server\Trailers;
use Amp\PHPUnit\AsyncTestCase;

class TrailersTest extends AsyncTestCase
{
    public function testMessageHasHeader(): void
    {
        $future = Future::complete(['fooHeader' => 'barValue']);

        $trailers = new Trailers($future, ['fooHeader']);
        $trailers = $trailers->await();

        self::assertTrue($trailers->hasHeader('fooHeader'));
        self::assertSame('barValue', $trailers->getHeader('fooHeader'));
    }

    public function testHasHeaderReturnsFalseForEmptyArrayValue(): void
    {
        $future = Future::complete(['fooHeader' => []]);

        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage('Trailers do not contain the expected fields');

        $trailers = new Trailers($future, ['fooHeader']);
        self::assertFalse(($trailers->await())->hasHeader('fooHeader'));
    }

    public function testDisallowedFieldsInConstructor(): void
    {
        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage("Field 'content-length' is not allowed in trailers");

        $trailers = new Trailers(Future::complete(null), ['content-length']);
    }

    public function testDisallowedFieldsInFuture(): void
    {
        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage("Field 'content-length' is not allowed in trailers");

        $trailers = new Trailers(Future::complete(['content-length' => '0']));

        $trailers->await();
    }
}
