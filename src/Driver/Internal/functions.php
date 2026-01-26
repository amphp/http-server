<?php

namespace Amp\Http\Server\Driver\Internal;

use League\Uri;
use Psr\Http\Message\UriInterface as PsrUri;

/**
 * @internal
 * @throws Uri\Contracts\UriException
 */
function createUriFromString(string $uri): PsrUri
{
    if (\method_exists(Uri\Http::class, 'new')) {
        return Uri\Http::new($uri);
    }

    return Uri\Http::createFromString($uri);
}

/**
 * @internal
 * @throws Uri\Contracts\UriException
 */
function createUriFromComponents(array $components): PsrUri
{
    if (\method_exists(Uri\Http::class, 'fromComponents')) {
        return Uri\Http::fromComponents($components);
    }

    return Uri\Http::createFromComponents($components);
}
