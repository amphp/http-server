<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

use Amp\Http\Internal\HPackNative;

/**
 * @internal
 * @psalm-import-type HeaderArray from \Amp\Http\HPack
 */
class QPack
{
    /** @see RFC 9204 Appendix A */
    const LAST_INDEX = 98;
    const TABLE = [ // starts at 0
        [":authority", ""],
        [":path", "/"],
        ["age", "0"],
        ["content-disposition", ""],
        ["content-length", "0"],
        ["cookie", ""],
        ["date", ""],
        ["etag", ""],
        ["if-modified-since", ""],
        ["if-none-match", ""],
        ["last-modified", ""],
        ["link", ""],
        ["location", ""],
        ["referer", ""],
        ["set-cookie", ""],
        [":method", "CONNECT"],
        [":method", "DELETE"],
        [":method", "GET"],
        [":method", "HEAD"],
        [":method", "OPTIONS"],
        [":method", "POST"],
        [":method", "PUT"],
        [":scheme", "http"],
        [":scheme", "https"],
        [":status", "103"],
        [":status", "200"],
        [":status", "304"],
        [":status", "404"],
        [":status", "503"],
        ["accept", "*/*"],
        ["accept", "application/dns-message"],
        ["accept-encoding", "gzip, deflate, br"],
        ["accept-ranges", "bytes"],
        ["access-control-allow-headers", "cache-control"],
        ["access-control-allow-headers", "content-type"],
        ["access-control-allow-origin", "*"],
        ["cache-control", "max-age=0"],
        ["cache-control", "max-age=2592000"],
        ["cache-control", "max-age=604800"],
        ["cache-control", "no-cache"],
        ["cache-control", "no-store"],
        ["cache-control", "public, max-age=31536000"],
        ["content-encoding", "br"],
        ["content-encoding", "gzip"],
        ["content-type", "application/dns-message"],
        ["content-type", "application/javascript"],
        ["content-type", "application/json"],
        ["content-type", "application/x-www-form-urlencoded"],
        ["content-type", "image/gif"],
        ["content-type", "image/jpeg"],
        ["content-type", "image/png"],
        ["content-type", "text/css"],
        ["content-type", "text/html; charset=utf-8"],
        ["content-type", "text/plain"],
        ["content-type", "text/plain;charset=utf-8"],
        ["range", "bytes=0-"],
        ["strict-transport-security", "max-age=31536000"],
        ["strict-transport-security", "max-age=31536000; includesubdomains"],
        ["strict-transport-security", "max-age=31536000; includesubdomains; preload"],
        ["vary", "accept-encoding"],
        ["vary", "origin"],
        ["x-content-type-options", "nosniff"],
        ["x-xss-protection", "1; mode=block"],
        [":status", "100"],
        [":status", "204"],
        [":status", "206"],
        [":status", "302"],
        [":status", "400"],
        [":status", "403"],
        [":status", "421"],
        [":status", "425"],
        [":status", "500"],
        ["accept-language", ""],
        ["access-control-allow-credentials", "FALSE"],
        ["access-control-allow-credentials", "TRUE"],
        ["access-control-allow-headers", "*"],
        ["access-control-allow-methods", "get"],
        ["access-control-allow-methods", "get, post, options"],
        ["access-control-allow-methods", "options"],
        ["access-control-expose-headers", "content-length"],
        ["access-control-request-headers", "content-type"],
        ["access-control-request-method", "get"],
        ["access-control-request-method", "post"],
        ["alt-svc", "clear"],
        ["authorization", ""],
        ["content-security-policy", "script-src 'none'; object-src 'none'; base-uri 'none'"],
        ["early-data", "1"],
        ["expect-ct", ""],
        ["forwarded", ""],
        ["if-range", ""],
        ["origin", ""],
        ["purpose", "prefetch"],
        ["server", ""],
        ["timing-allow-origin", "*"],
        ["upgrade-insecure-requests", "1"],
        ["user-agent", ""],
        ["x-forwarded-for", ""],
        ["x-frame-options", "deny"],
        ["x-frame-options", "sameorigin"],
    ];

    /**
     * @psalm-suppress MoreSpecificReturnType
     * @return non-negative-int
     */
    private static function decodeDynamicInteger(string $input, int $maxBits, int &$off): int
    {
        if (!isset($input[$off])) {
            throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, 'Invalid input data, too short for dynamic integer');
        }

        $int = \ord($input[$off++]) & $maxBits;
        if ($maxBits !== $int) {
            /** @psalm-suppress LessSpecificReturnStatement https://github.com/vimeo/psalm/issues/10667 */
            return $int;
        }

        $bitshift = 0;
        do {
            if (!isset($input[$off])) {
                throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, 'Invalid input data, too short for dynamic integer');
            }

            $c = \ord($input[$off++]);
            $int += ($c & 0x7f) << $bitshift;
            $bitshift += 7;

            if ($int > 0x7FFFFFFF) {
                throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, 'Invalid integer, too large');
            }
        } while ($c & 0x80);

        /** @psalm-suppress InvalidReturnStatement https://github.com/vimeo/psalm/issues/9902 */
        return $int;
    }

    public static function decodeDynamicField(string $input, int $startBits, int &$off): string
    {
        $startOff = $off;
        $length = self::decodeDynamicInteger($input, $startBits, $off);
        if (\strlen($input) < $off + $length) {
            throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, 'Invalid input data, too short for string field');
        }
        $huffman = \ord($input[$startOff]) & ($startBits + 1);
        $string = \substr($input, $off, $length);
        $off += $length;

        if ($huffman) {
            $string = HPackNative::huffmanDecode($string);
            if ($string === null) {
                throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, 'Invalid huffman encoded sequence');
            }
        }

        return $string;
    }

    public function decode(string $input, int &$off): array
    {
        // @TODO implementation is deliberately primitive...: we just enforce dynamic table size 0
        $headers = [];
        $len = \strlen($input);

        // skip Required Insert Count, given that we don't accept a dynamic table here
        // same for the base
        if ($len < $off + 2) {
            throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, "QPack HEADERS packet too small");
        }

        $off += 2;

        while ($off < $len) {
            $c = \ord($input[$off]);
            if ($c & 0x80) {
                $index = self::decodeDynamicInteger($input, 0x3F, $off);
                // indexed field line
                if ($c & 0x40) {
                    // static table
                    if ($index > self::LAST_INDEX) {
                        throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, "Tried to access index $index from the static table.");
                    }
                    $headers[] = self::TABLE[$index];

                } else {
                    throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, "Unexpected dynamic field reference");
                }
            } elseif ($c & 0x40) {
                // Literal field line with name reference
                if ($c & 0x10) {
                    // static table
                    $index = self::decodeDynamicInteger($input, 0xF, $off);
                    if ($index > self::LAST_INDEX) {
                        throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, "Tried to access index $index from the static table.");
                    }
                    $name = self::TABLE[$index][0];
                    $value = self::decodeDynamicField($input, 0x7F, $off);
                    $headers[] = [$name, $value];
                } else {
                    throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, "Unexpected dynamic field reference");
                }
            } elseif ($c & 0x20) {
                // literal field line with literal name
                $name = self::decodeDynamicField($input, 0x7, $off);
                $value = self::decodeDynamicField($input, 0x7F, $off);
                $headers[] = [$name, $value];
            } else {
                // 0x10 is Indexed field line with post-base index
                // (otherwise) is Literal field line with post-base name reference
                throw new QPackException(Http3Error::QPACK_DECOMPRESSION_FAILED, "Unexpected dynamic field reference");
            }
        }

        return $headers;
    }

    private static function encodeDynamicInteger(int $maxStartValue, int $int): string
    {
        if ($int < $maxStartValue) {
            return \chr($int);
        }

        $out = \chr($maxStartValue);
        $int -= $maxStartValue;

        for ($i = 0; ($int >> $i) >= 0x80; $i += 7) {
            $out .= \chr(0x80 | (($int >> $i) & 0x7f));
        }
        return $out . \chr($int >> $i);
    }

    private static function encodeDynamicField(int $startBits, string $input): string
    {
        return self::encodeDynamicInteger($startBits, \strlen($input)) . $input;
    }

    /**
     * @param HeaderArray $headers
     */
    public function encode(array $headers): string
    {
        // @TODO implementation is deliberately primitive... [doesn't use any dynamic table...]
        $encodedHeaders = [];
        foreach ($headers as [$name, $value]) {
            $encodedHeaders[] = ("\x20" | self::encodeDynamicField(0x07, $name)) . self::encodeDynamicField(0x7F, $value);
        }

        return "\0\0" . \implode($encodedHeaders);
    }
}
