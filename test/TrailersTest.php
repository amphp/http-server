<?php

namespace Amp\Http\Server\Test;

use Amp\Future;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Server\Trailers;
use Amp\PHPUnit\AsyncTestCase;

class TrailersTest extends AsyncTestCase
{
    public function testMessageHasHeader(): void
    {
        $promise = Future::complete(['fooHeader' => 'barValue']);

        $trailers = new Trailers($promise, ['fooHeader']);
        $trailers = $trailers->await();

        self::assertTrue($trailers->hasHeader('fooHeader'));
        self::assertSame('barValue', $trailers->getHeader('fooHeader'));
    }

    public function testHasHeaderReturnsFalseForEmptyArrayValue(): void
    {
        $promise = Future::complete(['fooHeader' => []]);

        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage('Trailers do not contain the expected fields');

        $trailers = new Trailers($promise, ['fooHeader']);
        self::assertFalse(($trailers->await())->hasHeader('fooHeader'));
    }

    public function testDisallowedFieldsInConstructor(): void
    {
        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage("Field 'content-length' is not allowed in trailers");

        $trailers = new Trailers(Future::complete(null), ['content-length']);
    }

    public function testDisallowedFieldsInPromiseResolution(): void
    {
        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage("Field 'content-length' is not allowed in trailers");

        $trailers = new Trailers(Future::complete(['content-length' => 0]));

        $trailers->await();
    }
}
