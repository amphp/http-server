<?php

namespace Aerys;

class HPack {
    const HUFFMAN_CODE = [
        /* 0x00 */ 0x1ff8, 0x7fffd8, 0xfffffe2, 0xfffffe3, 0xfffffe4, 0xfffffe5, 0xfffffe6, 0xfffffe7,
        /* 0x08 */ 0xfffffe8, 0xffffea, 0x3ffffffc, 0xfffffe9, 0xfffffea, 0x3ffffffd, 0xfffffeb, 0xfffffec,
        /* 0x10 */ 0xfffffed, 0xfffffee, 0xfffffef, 0xffffff0, 0xffffff1, 0xffffff2, 0x3ffffffe, 0xffffff3,
        /* 0x18 */ 0xffffff4, 0xffffff5, 0xffffff6, 0xffffff7, 0xffffff8, 0xffffff9, 0xffffffa, 0xffffffb,
        /* 0x20 */ 0x14, 0x3f8, 0x3f9, 0xffa, 0x1ff9, 0x15, 0xf8, 0x7fa,
        /* 0x28 */ 0x3fa, 0x3fb, 0xf9, 0x7fb, 0xfa, 0x16, 0x17, 0x18,
        /* 0x30 */ 0x0, 0x1, 0x2, 0x19, 0x1a, 0x1b, 0x1c, 0x1d,
        /* 0x38 */ 0x1e, 0x1f, 0x5c, 0xfb, 0x7ffc, 0x20, 0xffb, 0x3fc,
        /* 0x40 */ 0x1ffa, 0x21, 0x5d, 0x5e, 0x5f, 0x60, 0x61, 0x62,
        /* 0x48 */ 0x63, 0x64, 0x65, 0x66, 0x67, 0x68, 0x69, 0x6a,
        /* 0x50 */ 0x6b, 0x6c, 0x6d, 0x6e, 0x6f, 0x70, 0x71, 0x72,
        /* 0x58 */ 0xfc, 0x73, 0xfd, 0x1ffb, 0x7fff0, 0x1ffc, 0x3ffc, 0x22,
        /* 0x60 */ 0x7ffd, 0x3, 0x23, 0x4, 0x24, 0x5, 0x25, 0x26,
        /* 0x68 */ 0x27, 0x6, 0x74, 0x75, 0x28, 0x29, 0x2a, 0x7,
        /* 0x70 */ 0x2b, 0x76, 0x2c, 0x8, 0x9, 0x2d, 0x77, 0x78,
        /* 0x78 */ 0x79, 0x7a, 0x7b, 0x7ffe, 0x7fc, 0x3ffd, 0x1ffd, 0xffffffc,
        /* 0x80 */ 0xfffe6, 0x3fffd2, 0xfffe7, 0xfffe8, 0x3fffd3, 0x3fffd4, 0x3fffd5, 0x7fffd9,
        /* 0x88 */ 0x3fffd6, 0x7fffda, 0x7fffdb, 0x7fffdc, 0x7fffdd, 0x7fffde, 0xffffeb, 0x7fffdf,
        /* 0x90 */ 0xffffec, 0xffffed, 0x3fffd7, 0x7fffe0, 0xffffee, 0x7fffe1, 0x7fffe2, 0x7fffe3,
        /* 0x98 */ 0x7fffe4, 0x1fffdc, 0x3fffd8, 0x7fffe5, 0x3fffd9, 0x7fffe6, 0x7fffe7, 0xffffef,
        /* 0xA0 */ 0x3fffda, 0x1fffdd, 0xfffe9, 0x3fffdb, 0x3fffdc, 0x7fffe8, 0x7fffe9, 0x1fffde,
        /* 0xA8 */ 0x7fffea, 0x3fffdd, 0x3fffde, 0xfffff0, 0x1fffdf, 0x3fffdf, 0x7fffeb, 0x7fffec,
        /* 0xB0 */ 0x1fffe0, 0x1fffe1, 0x3fffe0, 0x1fffe2, 0x7fffed, 0x3fffe1, 0x7fffee, 0x7fffef,
        /* 0xB8 */ 0xfffea, 0x3fffe2, 0x3fffe3, 0x3fffe4, 0x7ffff0, 0x3fffe5, 0x3fffe6, 0x7ffff1,
        /* 0xC0 */ 0x3ffffe0, 0x3ffffe1, 0xfffeb, 0x7fff1, 0x3fffe7, 0x7ffff2, 0x3fffe8, 0x1ffffec,
        /* 0xC8 */ 0x3ffffe2, 0x3ffffe3, 0x3ffffe4, 0x7ffffde, 0x7ffffdf, 0x3ffffe5, 0xfffff1, 0x1ffffed,
        /* 0xD0 */ 0x7fff2, 0x1fffe3, 0x3ffffe6, 0x7ffffe0, 0x7ffffe1, 0x3ffffe7, 0x7ffffe2, 0xfffff2,
        /* 0xD8 */ 0x1fffe4, 0x1fffe5, 0x3ffffe8, 0x3ffffe9, 0xffffffd, 0x7ffffe3, 0x7ffffe4, 0x7ffffe5,
        /* 0xE0 */ 0xfffec, 0xfffff3, 0xfffed, 0x1fffe6, 0x3fffe9, 0x1fffe7, 0x1fffe8, 0x7ffff3,
        /* 0xE8 */ 0x3fffea, 0x3fffeb, 0x1ffffee, 0x1ffffef, 0xfffff4, 0xfffff5, 0x3ffffea, 0x7ffff4,
        /* 0xF0 */ 0x3ffffeb, 0x7ffffe6, 0x3ffffec, 0x3ffffed, 0x7ffffe7, 0x7ffffe8, 0x7ffffe9, 0x7ffffea,
        /* 0xF8 */ 0x7ffffeb, 0xffffffe, 0x7ffffec, 0x7ffffed, 0x7ffffee, 0x7ffffef, 0x7fffff0, 0x3ffffee,
        /* end! */ 0x3fffffff
    ];

    const HUFFMAN_CODE_LENGTHS = [
        /* 0x00 */ 13, 23, 28, 28, 28, 28, 28, 28,
        /* 0x08 */ 28, 24, 30, 28, 28, 30, 28, 28,
        /* 0x10 */ 28, 28, 28, 28, 28, 28, 30, 28,
        /* 0x18 */ 28, 28, 28, 28, 28, 28, 28, 28,
        /* 0x20 */ 6, 10, 10, 12, 13, 6, 8, 11,
        /* 0x28 */ 10, 10, 8, 11, 8, 6, 6, 6,
        /* 0x30 */ 5, 5, 5, 6, 6, 6, 6, 6,
        /* 0x38 */ 6, 6, 7, 8, 15, 6, 12, 10,
        /* 0x40 */ 13, 6, 7, 7, 7, 7, 7, 7,
        /* 0x48 */ 7, 7, 7, 7, 7, 7, 7, 7,
        /* 0x50 */ 7, 7, 7, 7, 7, 7, 7, 7,
        /* 0x58 */ 8, 7, 8, 13, 19, 13, 14, 6,
        /* 0x60 */ 15, 5, 6, 5, 6, 5, 6, 6,
        /* 0x68 */ 6, 5, 7, 7, 6, 6, 6, 5,
        /* 0x70 */ 6, 7, 6, 5, 5, 6, 7, 7,
        /* 0x78 */ 7, 7, 7, 15, 11, 14, 13, 28,
        /* 0x80 */ 20, 22, 20, 20, 22, 22, 22, 23,
        /* 0x88 */ 22, 23, 23, 23, 23, 23, 24, 23,
        /* 0x90 */ 24, 24, 22, 23, 24, 23, 23, 23,
        /* 0x98 */ 23, 21, 22, 23, 22, 23, 23, 24,
        /* 0xA0 */ 22, 21, 20, 22, 22, 23, 23, 21,
        /* 0xA8 */ 23, 22, 22, 24, 21, 22, 23, 23,
        /* 0xB0 */ 21, 21, 22, 21, 23, 22, 23, 23,
        /* 0xB8 */ 20, 22, 22, 22, 23, 22, 22, 23,
        /* 0xC0 */ 26, 26, 20, 19, 22, 23, 22, 25,
        /* 0xC8 */ 26, 26, 26, 27, 27, 26, 24, 25,
        /* 0xD0 */ 19, 21, 26, 27, 27, 26, 27, 24,
        /* 0xD8 */ 21, 21, 26, 26, 28, 27, 27, 27,
        /* 0xE0 */ 20, 24, 20, 21, 22, 21, 21, 23,
        /* 0xE8 */ 22, 22, 25, 25, 24, 24, 26, 23,
        /* 0xF0 */ 26, 27, 26, 26, 27, 27, 27, 27,
        /* 0xF8 */ 27, 28, 27, 27, 27, 27, 27, 26,
        /* end! */ 30
    ];

    private static $huffman_lookup;
    private static $huffman_codes;
    private static $huffman_lens;

    private $headers = [];
    private $maxSize = 4096;
    private $size = 0;

    public static function init() {
        self::$huffman_lookup = self::huffman_lookup_init();
        self::$huffman_codes = self::huffman_codes_init();
        self::$huffman_lens = self::huffman_lens_init();
    }

    // (micro-)optimized decode
    private static function huffman_lookup_init() {
        gc_disable();
        $encodingAccess = [];
        $terminals = [];

        foreach (self::HUFFMAN_CODE as $chr => $bits) {
            $len = self::HUFFMAN_CODE_LENGTHS[$chr];
            for ($bit = 0; $bit < 8; $bit++) {
                $offlen = $len + $bit;
                $next = &$encodingAccess[$bit];
                for ($byte = (int)(($offlen - 1) / 8); $byte > 0; $byte--) {
                    $cur = \str_pad(\decbin(($bits >> ($byte * 8 - (0x30 - $offlen) % 8)) & 0xFF), 8, "0", STR_PAD_LEFT);
                    if (isset($next[$cur]) && $next[$cur][0] != $encodingAccess[0]) {
                        $next = &$next[$cur][0];
                    } else {
                        $tmp = &$next;
                        unset($next);
                        $tmp[$cur] = [&$next, null];
                    }
                }
                $key = \str_pad(\decbin($bits & ((1 << ((($offlen - 1) % 8) + 1)) - 1)), ((($offlen - 1) % 8) + 1), "0", STR_PAD_LEFT);
                $next[$key] = [null, $chr > 0xFF ? "" : \chr($chr)];
                if ($offlen % 8) {
                    $terminals[$offlen % 8][] = [$key, &$next];
                } else {
                    $next[$key][0] = &$encodingAccess[0];
                }
            }
        }

        $memoize = [];
        for ($off = 7; $off > 0; $off--) {
            foreach ($terminals[$off] as &$terminal) {
                $key = $terminal[0];
                $next = &$terminal[1];
                if ($next[$key][0] === null) {
                    foreach ($encodingAccess[$off] as $chr => &$cur) {
                        $next[($memoize[$key] ?? $memoize[$key] = \str_pad($key, 8, "0", STR_PAD_RIGHT)) | $chr] = [&$cur[0], $next[$key][1] != "" ? $next[$key][1] . $cur[1] : ""];
                    }

                    unset($next[$key]);
                }
            }
        }

        $memoize = [];
        for ($off = 7; $off > 0; $off--) {
            foreach ($terminals[$off] as &$terminal) {
                $next = &$terminal[1];
                foreach ($next as $k => $v) {
                    if (\strlen($k) != 1) {
                        $next[$memoize[$k] ?? $memoize[$k] = \chr(\bindec($k))] = $v;
                        unset($next[$k]);
                    }
                }
            }
        }

        unset($tmp, $cur, $next, $terminals, $terminal);
        gc_enable();
        gc_collect_cycles();
        return $encodingAccess[0];
    }

    public static function huffman_decode($input) {
        $lookup = self::$huffman_lookup;
        $len = \strlen($input);
        $out = str_repeat("\0", $len / 5 * 8 + 1); // max length

        for ($off = $i = 0; $i < $len; $i++) {
            list($lookup, $chr) = $lookup[$input[$i]];

            if ($chr != null) {
                $out[$off++] = $chr;
                if (isset($chr[1])) {
                    $out[$off++] = $chr[1];
                    continue;
                }
                continue;
            }
            if ($chr === "") {
                return null;
            }
        }

        return substr($out, 0, $off);
    }

    private static function huffman_codes_init() {
        $lookup = [];

        for ($chr = 0; $chr <= 0xFF; $chr++) {
            $bits = self::HUFFMAN_CODE[$chr];
            $len = self::HUFFMAN_CODE_LENGTHS[$chr];
            for ($bit = 0; $bit < 8; $bit++) {
                $bytes = floor(($len + $bit - 1) / 8);
                for ($byte = $bytes; $byte >= 0; $byte--) {
                    $lookup[$bit][chr($chr)][] = chr($byte ? $bits >> ($len - ($bytes - $byte + 1) * 8 + $bit) : ($bits << ((0x30 - $len - $bit) % 8)));
                }
            }
        }

        return $lookup;
    }

    private static function huffman_lens_init() {
        $lens = [];

        for ($chr = 0; $chr <= 0xFF; $chr++) {
            $lens[chr($chr)] = self::HUFFMAN_CODE_LENGTHS[$chr];
        }

        return $lens;
    }

    public static function huffman_encode($input) {
        $codes = self::$huffman_codes;
        $lens = self::$huffman_lens;

        $len = \strlen($input);
        $out = \str_repeat("\0", $len * 5 + 1); // max length

        for ($bitcount = $i = 0; $i < $len; $i++) {
            $chr = $input[$i];
            $byte = $bitcount >> 3;
            foreach ($codes[$bitcount % 8][$chr] as $bits) {
                $out[$byte] = $out[$byte] | $bits;
                $byte++;
            }
            $bitcount += $lens[$chr];
        }

        $bytes = $bitcount / 8;
        $e = (int)\ceil($bytes);
        if ($e != $bytes) {
            $out[$e - 1] = $out[$e - 1] | \chr(0xFF >> $bitcount % 8);
        }
        return \substr($out, 0, $e);
    }

    /** @see RFC 7541 Appendix A */
    const LAST_INDEX = 61;
    const TABLE = [ // starts at 1
        [":authority", ""],
        [":method", "GET"],
        [":method", "POST"],
        [":path", "/"],
        [":path", "/index.html"],
        [":scheme", "http"],
        [":scheme", "https"],
        [":status", "200"],
        [":status", "204"],
        [":status", "206"],
        [":status", "304"],
        [":status", "400"],
        [":status", "404"],
        [":status", "500"],
        ["accept-charset", ""],
        ["accept-encoding", "gzip, deflate"],
        ["accept-language", ""],
        ["accept-ranges", ""],
        ["accept", ""],
        ["access-control-allow-origin", ""],
        ["age", ""],
        ["allow", ""],
        ["authorization", ""],
        ["cache-control", ""],
        ["content-disposition", ""],
        ["content-encoding", ""],
        ["content-language", ""],
        ["content-length", ""],
        ["content-location", ""],
        ["content-range", ""],
        ["content-type", ""],
        ["cookie", ""],
        ["date", ""],
        ["etag", ""],
        ["expect", ""],
        ["expires", ""],
        ["from", ""],
        ["host", ""],
        ["if-match", ""],
        ["if-modified-since", ""],
        ["if-none-match", ""],
        ["if-range", ""],
        ["if-unmodified-since", ""],
        ["last-modified", ""],
        ["link", ""],
        ["location", ""],
        ["max-forwards", ""],
        ["proxy-authentication", ""],
        ["proxy-authorization", ""],
        ["range", ""],
        ["referer", ""],
        ["refresh", ""],
        ["retry-after", ""],
        ["server", ""],
        ["set-cookie", ""],
        ["strict-transport-security", ""],
        ["transfer-encoding", ""],
        ["user-agent", ""],
        ["vary", ""],
        ["via", ""],
        ["www-authenticate", ""]
    ];

    private static function decode_dynamic_integer(&$input, &$off) {
        $c = \ord($input[$off++]);
        $int = $c & 0x7f;
        $i = 0;
        while ($c & 0x80) {
            if (!isset($input[$off])) {
                return -0x80;
            }
            $c = \ord($input[$off++]);
            $int += ($c & 0x7f) << (++$i * 7);
        }
        return $int;
    }

    // removal of old entries as per 4.4
    public function table_resize($maxSize = null) {
        if (isset($maxSize)) {
            $this->maxSize = $maxSize;
        }
        while ($this->size > $this->maxSize) {
            list($name, $value) = \array_pop($this->headers);
            $this->size -= 32 + \strlen($name) + \strlen($value);
        }
    }

    public function decode($input) {
        $headers = [];
        $off = 0;
        $inputlen = \strlen($input);

        // dynamic $table as per 2.3.2
        while ($off < $inputlen) {
            $index = \ord($input[$off++]);
            if ($index & 0x80) {
                // range check
                if ($index <= self::LAST_INDEX + 0x80) {
                    if ($index === 0x80) {
                        return null;
                    }
                    $headers[] = self::TABLE[$index - 0x81];
                } else {
                    if ($index == 0xff) {
                        $index = self::decode_dynamic_integer($input, $off) + 0xff;
                    }
                    $index -= 0x81 + self::LAST_INDEX;
                    if (!isset($this->headers[$index])) {
                        return null;
                    }
                    $headers[] = $this->headers[$index];
                }
            } elseif (($index & 0x60) != 0x20) { // (($index & 0x40) || !($index & 0x20)): bit 4: never index is ignored
                $dynamic = (bool)($index & 0x40);
                if ($index & ($dynamic ? 0x3f : 0x0f)) { // separate length
                    if ($dynamic) {
                        if ($index == 0x7f) {
                            $index = self::decode_dynamic_integer($input, $off) + 0x3f;
                        } else {
                            $index &= 0x3f;
                        }
                    } else {
                        $index &= 0x0f;
                        if ($index == 0x0f) {
                            $index = self::decode_dynamic_integer($input, $off) + 0x0f;
                        }
                    }
                    if ($index <= self::LAST_INDEX) {
                        $header = self::TABLE[$index - 1];
                    } else {
                        $header = $this->headers[$index - 1 - self::LAST_INDEX];
                    }
                } else {
                    $len = \ord($input[$off++]);
                    $huffman = $len & 0x80;
                    $len &= 0x7f;
                    if ($len == 0x7f) {
                        $len = self::decode_dynamic_integer($input, $off) + 0x7f;
                    }
                    if ($inputlen - $off < $len || $len <= 0) {
                        return null;
                    }
                    if ($huffman) {
                        $header = [self::huffman_decode(\substr($input, $off, $len))];
                    } else {
                        $header = [\substr($input, $off, $len)];
                    }
                    $off += $len;
                }
                if ($off == $inputlen) {
                    return null;
                }
                $len = \ord($input[$off++]);
                $huffman = $len & 0x80;
                $len &= 0x7f;
                if ($len == 0x7f) {
                    $len = self::decode_dynamic_integer($input, $off) + 0x7f;
                }
                if ($inputlen - $off < $len || $len < 0) {
                    return null;
                }
                if ($huffman) {
                    $header[1] = self::huffman_decode(\substr($input, $off, $len));
                } else {
                    $header[1] = \substr($input, $off, $len);
                }
                $off += $len;
                if ($dynamic) {
                    \array_unshift($this->headers, $header);
                    $this->size += 32 + \strlen($header[0]) + \strlen($header[1]);
                    if ($this->maxSize < $this->size) {
                        $this->table_resize();
                    }
                }
                $headers[] = $header;
            } else { //if ($index & 0x20) {
                if ($index == 0x3f) {
                    $index = self::decode_dynamic_integer($input, $off) + 0x40;
                }
                if ($index > 4096) { // initial limit … may be adjusted??
                    return null;
                } else {
                    $this->table_resize($index);
                }
            }
        }

        return $headers;
    }

    private static function encode_dynamic_integer($int) {
        $out = "";
        $i = 0;
        while (($int >> $i) > 0x80) {
            $out .= \chr(0x80 | (($int >> $i) & 0x7f));
            $i += 7;
        }
        return $out . chr($int >> $i);
    }

    public static function encode($headers) {
        // @TODO implementation is deliberately primitive... [doesn't use any dynamic table...]
        $output = [];

        foreach ($headers as $name => $values) {
            foreach ((array) $values as $value) {
                foreach (self::TABLE as $index => list($header_name)) {
                    if ($name == $header_name) {
                        break;
                    }
                }
                if ($name == $header_name) {
                    if (++$index < 0x10) {
                        $output[] = \chr($index);
                    } else {
                        $output[] = "\x0f" . \chr($index - 0x0f);
                    }
                } elseif (\strlen($name) < 0x7f) {
                    $output[] = "\0" . \chr(\strlen($name)) . $name;
                } else {
                    $output[] = "\0\x7f" . self::encode_dynamic_integer(\strlen($name) - 0x7f) . $name;
                }
                if (\strlen($value) < 0x7f) {
                    $output[] = \chr(\strlen($value)) . $value;
                } else {
                    $output[] = "\x7f" . self::encode_dynamic_integer(\strlen($value) - 0x7f) . $value;
                }
            }
        }

        return implode($output);
    }
}

HPack::init();