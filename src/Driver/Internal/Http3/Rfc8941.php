<?php declare(strict_types=1);

namespace Amp\Http\Server\Driver\Internal\Http3;

use Amp\Http\Server\Driver\Internal\Http3\Rfc8941\Boolean;
use Amp\Http\Server\Driver\Internal\Http3\Rfc8941\Bytes;
use Amp\Http\Server\Driver\Internal\Http3\Rfc8941\Date;
use Amp\Http\Server\Driver\Internal\Http3\Rfc8941\DisplayString;
use Amp\Http\Server\Driver\Internal\Http3\Rfc8941\InnerList;
use Amp\Http\Server\Driver\Internal\Http3\Rfc8941\Item;
use Amp\Http\Server\Driver\Internal\Http3\Rfc8941\Number;
use Amp\Http\Server\Driver\Internal\Http3\Rfc8941\Str;
use Amp\Http\Server\Driver\Internal\Http3\Rfc8941\Token;

// Including support for the extension for RFC 8941, see https://datatracker.ietf.org/doc/draft-ietf-httpbis-sfbis
/**
 * @psalm-type Rfc8941ListItem = Item<list<Item>>
 * @psalm-type Rfc8941SingleItem = Item<void>
 * @psalm-type Rfc8941BareItem = int|float|string|bool
 * @psalm-type Rfc8941Parameters = array<string, Rfc8941BareItem>
 */
class Rfc8941
{
    /**
     * @param string[]|string $value
     * @psalm-return null|list<Rfc8941ListItem>
     */
    public static function parseList(array|string $value): ?array
    {
        $string = \is_array($value) ? \implode(",", $value) : $value;

        $i = \strspn($string, " ");
        $len = \strlen($string);
        if ($len === $i) {
            return [];
        }

        $list = [];
        while (true) {
            if (null === $list[] = self::parseItemOrInnerList($string, $i)) {
                return null;
            }
            $i += \strspn($string, " \t", $i);
            if ($i >= $len) {
                return $list;
            }
            if ($string[$i] !== ",") {
                return null;
            }
            $i += \strspn($string, " \t", ++$i);
            if ($i >= $len) {
                return null;
            }
        }
    }

    /**
     * @param string[]|string $value
     * @psalm-return array<string, Rfc8941ListItem>
     */
    public static function parseDictionary(array|string $value): ?array
    {
        $string = \is_array($value) ? \implode(",", $value) : $value;

        $i = \strspn($string, " ");
        $len = \strlen($string);
        if ($len === $i) {
            return [];
        }

        $values = [];
        while (true) {
            $i += \strspn($string, " ", $i);
            if (null === $key = self::parseKey($string, $i)) {
                return null;
            }
            if ($i < $len && $string[$i] === "=") {
                ++$i;
                if (null === $values[$key] = self::parseItemOrInnerList($string, $i)) {
                    return null;
                }
            } else {
                if (null === $parameters = self::parseParameters($string, $i)) {
                    return null;
                }
                $values[$key] = new Boolean(true, $parameters);
            }
            $i += \strspn($string, " \t", $i);
            if ($i >= $len) {
                return $values;
            }
            if ($string[$i] !== ",") {
                return null;
            }
            $i += \strspn($string, " \t", ++$i);
            if ($i >= $len) {
                return null;
            }
        }
    }

    /** @psalm-param null|Rfc8941SingleItem */
    public static function parseItem(string $string): ?Item
    {
        $i = \strspn($string, " ");
        if ($i === \strlen($string)) {
            return null;
        }
        $parsed = self::parseItemInternal($string, $i);
        return $i + \strspn($string, " ", $i) < \strlen($string) ? null : $parsed;
    }

    /** @psalm-return null|Rfc8941ListItem */
    private static function parseItemOrInnerList(string $string, int &$i): ?Item
    {
        $len = \strlen($string);
        if ($string[$i] === "(") {
            $innerList = [];
            ++$i;
            while (true) {
                $i += \strspn($string, " ", $i);
                if ($i >= $len) {
                    return null;
                }
                if ($string[$i] === ")") {
                    ++$i;
                    if (null === $params = self::parseParameters($string, $i)) {
                        return null;
                    }
                    return new InnerList($innerList, $params);
                }
                $chr = $string[$i - 1];
                if ($chr !== " " && $chr !== "(") {
                    return null;
                }
                if (null === $innerList[] = self::parseItemInternal($string, $i)) {
                    return null;
                }
            }
        }
        return self::parseItemInternal($string, $i);
    }

    /** @psalm-param null|Rfc8941SingleItem */
    private static function parseItemInternal(string $string, int &$i): ?Item
    {
        if (null === $value = self::parseBareItem($string, $i, $class)) {
            return null;
        }
        if (null === $parameters = self::parseParameters($string, $i)) {
            return null;
        }
        return new $class($value, $parameters);
    }

    public static function parseIntegerOrDecimal(string $string): null|int|float
    {
        if ($string === "") {
            return null;
        }

        $i = \strspn($string, " ");
        $chr = \ord($string[$i]);
        if ($chr === \ord("-") || ($chr >= \ord('0') || $chr <= \ord('9'))) {
            $parsed = self::parseIntegerOrDecimalInternal($string, $i);
            return $i + \strspn($string, " ", $i) < \strlen($string) ? null : $parsed;
        }
        return null;
    }

    private static function parseIntegerOrDecimalInternal(string $string, int &$i): null|int|float
    {
        $len = \strlen($string);
        $sign = 1;
        if ($string[$i] === "-") {
            ++$i;
            $sign = -1;
        }
        $digits = \strspn($string, "0123456789", $i);
        if ($digits < 1) {
            return null;
        }
        $decimaldot = $i + $digits + 1;
        if ($decimaldot < $len && $string[$decimaldot - 1] === ".") {
            if ($digits > 12) {
                return null;
            }
            $decimals = \strspn($string, "0123456789", $decimaldot);
            if ($decimals < 1 || $decimals > 3) {
                return null;
            }
            $length = $decimaldot - $i + $decimals;
            $num = $sign * \substr($string, $i, $length);
            $i += $length;
        } elseif ($digits > 15) {
            return null;
        } else {
            $num = $sign * \substr($string, $i, $digits);
            $i += $digits;
        }
        return $num;
    }

    public static function parseString(string $string): ?string
    {
        if ($string === "") {
            return null;
        }

        $i = \strspn($string, " ");
        if ($string[$i++] === '"') {
            $parsed = self::parseStringInternal($string, $i);
            return $i + \strspn($string, " ", $i) < \strlen($string) ? null : $parsed;
        }
        return null;
    }

    private static function parseStringInternal(string $string, int &$i): ?string
    {
        $start = $i;
        $len = \strlen($string);
        $foundslash = false;
        while (true) {
            $i += \strspn($string, " !#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_`abcdefghijklmnopqrstuvwxyz{|}~", $i);
            if ($i >= $len) {
                return null;
            }
            if ($string[$i] === '"') {
                $str = \substr($string, $start, $i++ - $start);
                if ($foundslash) {
                    return \stripslashes($str);
                }
                return $str;
            }
            if ($string[$i] === "\\") {
                if (++$i >= $len) {
                    return null;
                }
                $foundslash = true;
                $chr = $string[$i++];
                if ($chr !== '"' && $chr !== "\\") {
                    return null;
                }
            } else {
                return null;
            }
        }
    }

    public static function parseToken(string $string): ?string
    {
        if ($string === "") {
            return null;
        }

        $i = \strspn($string, " ");
        $chr = \ord($string[$i]);
        if ($chr === \ord("*") || ($chr >= \ord('A') && $chr <= \ord("Z")) || ($chr >= \ord('a') && $chr <= \ord("z"))) {
            $parsed = self::parseTokenInternal($string, $i);
            return $i + \strspn($string, " ", $i) < \strlen($string) ? null : $parsed;
        }
        return null;
    }

    private static function parseTokenInternal(string $string, int &$i): string
    {
        $length = \strspn($string, ":/!#$%&'*+-.^_`|~0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ", $i);
        $str = \substr($string, $i, $length);
        $i += $length;
        return $str;
    }

    public static function parseByteSequence(string $string): ?string
    {
        if ($string === "") {
            return null;
        }

        $i = \strspn($string, " ");
        if ($string[$i++] === ':') {
            $parsed = self::parseByteSequenceInternal($string, $i);
            return $i + \strspn($string, " ", $i) < \strlen($string) ? null : $parsed;
        }
        return null;
    }

    private static function parseByteSequenceInternal(string $string, int &$i): ?string
    {
        $length = \strspn($string, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=", $i);
        $str = \base64_decode(\substr($string, $i, $length));
        $i += $length;
        if (!isset($string[$i]) || $string[$i++] !== ":") {
            return null;
        }
        return $str === false ? null : $str;
    }

    public static function parseBoolean(string $string): ?bool
    {
        if ($string === "") {
            return null;
        }

        $i = \strspn($string, " ");
        if ($string[$i++] === '?') {
            $parsed = self::parseBooleanInternal($string, $i);
            return $i + \strspn($string, " ", $i) < \strlen($string) ? null : $parsed;
        }
        return null;
    }

    private static function parseBooleanInternal(string $string, int &$i): ?bool
    {
        if (!isset($string[$i])) {
            return null;
        }
        $chr = $string[$i++];
        if ($chr === "0") {
            return false;
        }
        if ($chr === "1") {
            return true;
        }
        return null;
    }

    public static function parseDate(string $string): ?int
    {
        if ($string === "") {
            return null;
        }

        $i = \strspn($string, " ");
        if ($string[$i++] === '@') {
            $parsed = self::parseDateInternal($string, $i);
            return $i + \strspn($string, " ", $i) < \strlen($string) ? null : $parsed;
        }
        return null;
    }

    private static function parseDateInternal(string $string, int &$i): ?int
    {
        if (!isset($string[$i])) {
            return null;
        }
        $start = $i;
        if ($string[$i] === "-") {
            ++$i;
        }
        $length = \strspn($string, "0123456789", $i);
        if ($length < 1 || $length > 15) {
            return null;
        }
        if ($start !== $i) {
            $i += $length++;
        } else {
            $i += $length;
        }
        return (int) \substr($string, $start, $length);
    }

    public static function parseDisplayString(string $string): ?string
    {
        $i = \strspn($string, " ");
        if (\strlen($string) < $i + 3) {
            return null;
        }

        if ($string[$i++] === '%' && $string[$i++] === '"') {
            $parsed = self::parseDisplayStringInternal($string, $i);
            return $i + \strspn($string, " ", $i) < \strlen($string) ? null : $parsed;
        }
        return null;
    }

    private static function parseDisplayStringInternal(string $string, int &$i): ?string
    {
        $start = $i;
        $len = \strlen($string);
        $buf = "";
        while (true) {
            $i += \strspn($string, " !#$&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_`abcdefghijklmnopqrstuvwxyz{|}~\\", $i);
            if ($i >= $len) {
                return null;
            }
            if ($string[$i] === '%') {
                $buf .= \substr($string, $start, $i++ - $start);
                $hexlen = \strspn($string, "0123456789abcdef", $i, 2);
                if ($hexlen !== 2) {
                    return null;
                }
                $buf .= \chr(\hexdec(\substr($string, $i, 2)));
                $start = $i += 2;
                continue;
            }
            if ($string[$i] === '"') {
                $buf .= \substr($string, $start, $i++ - $start);
                if (!\preg_match('//u', $buf)) {
                    return null;
                }
                return $buf;
            }
            return null;
        }
    }

    /** @psalm-return null|Rfc8941BareItem */
    private static function parseBareItem(string $string, int &$i, &$class = ""): null|int|float|string|bool
    {
        $chr = \ord($string[$i]);
        if ($chr === \ord("-") || ($chr >= \ord('0') && $chr <= \ord('9'))) {
            $class = Number::class;
            return self::parseIntegerOrDecimalInternal($string, $i);
        }
        if ($chr === \ord('"')) {
            $class = Str::class;
            ++$i;
            return self::parseStringInternal($string, $i);
        }
        if ($chr === \ord("*") || ($chr >= \ord('A') && $chr <= \ord("Z")) || ($chr >= \ord('a') && $chr <= \ord("z"))) {
            $class = Token::class;
            return self::parseTokenInternal($string, $i);
        }
        if ($chr === \ord(":")) {
            $class = Bytes::class;
            ++$i;
            return self::parseByteSequenceInternal($string, $i);
        }
        if ($chr === \ord("%")) {
            $class = DisplayString::class;
            if (!isset($string[++$i]) || $string[$i++] !== '"') {
                return null;
            }
            return self::parseDisplayStringInternal($string, $i);
        }
        if ($chr === \ord("?")) {
            $class = Boolean::class;
            ++$i;
            return self::parseBooleanInternal($string, $i);
        }
        if ($chr === \ord("@")) {
            $class = Date::class;
            ++$i;
            return self::parseDateInternal($string, $i);
        }
        return null;
    }

    /** @psalm-return null|Rfc8941Parameters */
    private static function parseParameters(string $string, int &$i): ?array
    {
        $parameters = [];
        for ($len = \strlen($string); $i < $len;) {
            if ($string[$i] !== ";") {
                break;
            }
            $i += \strspn($string, " ", ++$i);

            if ($i >= $len) {
                return null;
            }

            if (null === $key = self::parseKey($string, $i)) {
                return null;
            }

            if ($i < $len && $string[$i] === "=") {
                ++$i;
                if (null === $item = self::parseBareItem($string, $i)) {
                    return null;
                }
                $parameters[$key] = $item;
            } else {
                $parameters[$key] = true;
            }
        }
        return $parameters;
    }

    private static function parseKey(string $string, int &$i): ?string
    {
        $chr = \ord($string[$i]);
        if ($chr !== \ord("*") && ($chr < \ord('a') || $chr > \ord('z'))) {
            return null;
        }

        $keystart = $i++;
        $i += \strspn($string, "*.-_abcdefghijklmnopqrstuvwxyz0123456789", $i);
        return \substr($string, $keystart, $i - $keystart);
    }
}
