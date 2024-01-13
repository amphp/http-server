<?php declare(strict_types=1);

namespace Amp\Http\Server\Test\Http;

use Amp\Http\Server\Driver\Internal\Http3\Rfc8941;
use Amp\Http\Server\Driver\Internal\Http3\Rfc8941\Item;
use PHPUnit\Framework\TestCase;

class Rfc8941Test extends TestCase
{
    /** @dataProvider provideAll */
    public function testAll(array $args): void
    {
        $this->executeTest(...$args);
    }

    public function executeTest(array $raw, string $header_type, $expected = null, bool $must_fail = false, bool $can_fail = false, $canonical = null, ...$ignoredArgs)
    {
        switch ($header_type) {
            case "item":
                $parsed = Rfc8941::parseItem($raw[0]);
                break;

            case "list":
                $parsed = Rfc8941::parseList($raw);
                break;

            case "dictionary":
                $parsed = Rfc8941::parseDictionary($raw);
                break;

            default:
                $this->fail("Unknown $header_type in dataset");
        }

        self::destructureItems($parsed);

        if ($parsed === null) {
            $this->assertTrue($must_fail || $can_fail);
        } elseif ($must_fail) {
            $this->assertNull($parsed);
        } else {
            $this->assertSame($expected, $parsed);
        }
    }

    public function provideAll(): array
    {
        $cases = [];
        foreach (\glob(__DIR__ . "/../../vendor/httpwg/structured-field-tests/*.json") as $file) {
            foreach (\json_decode(\file_get_contents($file), true) as $case) {

                // We don't test merging two headers here
                self::sanitize($case);
                $cases[\basename($file) . ": {$case["name"]}"] = [$case];
            }
        }
        return $cases;
    }

    public static function destructureItems(&$parsed)
    {
        if ($parsed instanceof Item) {
            $parsed = [$parsed->item, $parsed->parameters];
        }
        if (\is_array($parsed)) {
            foreach ($parsed as &$value) {
                self::destructureItems($value);
            }
        }
    }

    public static function sanitize(&$case)
    {
        $expected = &$case["expected"];
        self::sanitizeType($expected);
        if (\is_array($expected)) {
            if ($case["header_type"] === "list" || $case["header_type"] === "dictionary") {
                if ($case["header_type"] === "dictionary") {
                    self::recombineDictonary($expected);
                }
                foreach ($expected as &$item) {
                    if (\is_array($item[0])) {
                        foreach ($item[0] as &$innerItem) {
                            self::recombineDictonary($innerItem[1]);
                        }
                    }
                    self::recombineDictonary($item[1]);
                }
            } else {
                self::recombineDictonary($expected[1]);
            }
        }
    }

    public static function recombineDictonary(&$data)
    {
        $data = \array_combine(\array_column($data, 0), \array_column($data, 1));
    }

    public static function sanitizeType(&$data)
    {
        if (\is_array($data)) {
            foreach ($data as &$value) {
                if (isset($value["__type"])) {
                    if ($value["__type"] === "binary") {
                        // base32? wtf.
                        $str = \rtrim($value["value"], "=");
                        $buf = "";
                        $keys = \array_flip(\array_merge(\range('A', 'Z'), \range(2, 7)));
                        $byte = 0;
                        for ($i = 0, $len = \strlen($str); $i < $len; ++$i) {
                            $shift = (5 * ($i + 1)) % 8;
                            $byte = $byte << 5 | $keys[$str[$i]];
                            if ($shift < 5) {
                                $buf .= \chr($byte >> $shift);
                                $byte &= (1 << $shift) - 1;
                            }
                        }
                        if ((5 * $i) % 8 >= 5) {
                            $buf .= \chr($byte << (8 - (5 * $i) % 8));
                        }
                        $value = $buf;
                    } else {
                        $value = $value["value"];
                    }
                } else {
                    self::sanitizeType($value);
                }
            }
        }
    }
}
