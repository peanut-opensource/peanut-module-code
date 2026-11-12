<?php

declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use RuntimeException;

final class Semver
{
    private const VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

    public static function compare(string $left, string $right): int
    {
        $a = self::parse($left);
        $b = self::parse($right);
        foreach ([0, 1, 2] as $index) {
            $comparison = self::compareNumeric($a[$index], $b[$index]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        $leftPre = $a[3];
        $rightPre = $b[3];
        if ($leftPre === null || $rightPre === null) {
            return $leftPre === $rightPre ? 0 : ($leftPre === null ? 1 : -1);
        }
        $length = max(count($leftPre), count($rightPre));
        for ($index = 0; $index < $length; $index++) {
            if (!array_key_exists($index, $leftPre)) {
                return -1;
            }
            if (!array_key_exists($index, $rightPre)) {
                return 1;
            }
            $leftNumeric = ctype_digit($leftPre[$index]);
            $rightNumeric = ctype_digit($rightPre[$index]);
            if ($leftNumeric && $rightNumeric) {
                $comparison = self::compareNumeric($leftPre[$index], $rightPre[$index]);
            } elseif ($leftNumeric !== $rightNumeric) {
                $comparison = $leftNumeric ? -1 : 1;
            } else {
                $comparison = strcmp($leftPre[$index], $rightPre[$index]);
            }
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return 0;
    }

    public static function lessThan(string $left, string $right): bool
    {
        return self::compare($left, $right) < 0;
    }

    public static function greaterThanOrEqualTo(string $left, string $right): bool
    {
        return self::compare($left, $right) >= 0;
    }

    private static function compareNumeric(string $left, string $right): int
    {
        $length = strlen($left) <=> strlen($right);
        return $length !== 0 ? $length : strcmp($left, $right);
    }

    /** @return array{string,string,string,?list<string>} */
    private static function parse(string $version): array
    {
        if (preg_match(self::VERSION, $version, $matches) !== 1) {
            throw new RuntimeException('SEMVER_INVALID');
        }
        return [
            $matches[1],
            $matches[2],
            $matches[3],
            isset($matches[4]) && $matches[4] !== '' ? explode('.', $matches[4]) : null,
        ];
    }
}
