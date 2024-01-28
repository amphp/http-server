<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http;

use Amp\ByteStream\WritableStream;
use Amp\Http\Server\Driver\Internal\Http3\Http3Writer;

final class Rfc9297Writer implements CapsuleWriter
{
    public function __construct(private WritableStream $writer)
    {
    }

    public function write(int $type, string $buf)
    {
        $this->writer->write(Http3Writer::encodeVarint($type) . Http3Writer::encodeVarint(\strlen($buf)) . $buf);
    }
}
