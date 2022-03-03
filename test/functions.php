<?php

namespace Amp\Http\Server;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;

function streamChunks(string $message, int $length = 1): ReadableStream
{
    return new ReadableIterableStream(\str_split($message, $length));
}
