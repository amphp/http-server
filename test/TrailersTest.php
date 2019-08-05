<?php

namespace Amp\Http\Server\Test;

use Amp\Http\InvalidHeaderException;
use Amp\Http\Server\Trailers;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Success;

class TrailersTest extends AsyncTestCase
{
    public function testMessageHasHeader()
    {
        $promise = new Success(['fooHeader' => 'barValue']);

        $trailers = new Trailers($promise, ['fooHeader']);
        $trailers = yield $trailers->getTrailers();

        $this->assertTrue($trailers->hasHeader('fooHeader'));
        $this->assertSame('barValue', $trailers->getHeader('fooHeader'));
    }

    public function testHasHeaderReturnsFalseForEmptyArrayValue()
    {
        $promise = new Success(['fooHeader' => []]);

        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage('Trailers do not contain the expected fields');

        $trailers = new Trailers($promise, ['fooHeader']);
        $this->assertFalse((yield $trailers->getTrailers())->hasHeader('fooHeader'));
    }

    public function testDisallowedFieldsInConstructor()
    {
        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage("Field 'content-length' is not allowed in trailers");

        $trailers = new Trailers(new Success, ['content-length']);
    }

    public function testDisallowedFieldsInPromiseResolution()
    {
        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage("Field 'content-length' is not allowed in trailers");

        $trailers = new Trailers(new Success(['content-length' => 0]));

        yield $trailers->getTrailers();
    }
}
