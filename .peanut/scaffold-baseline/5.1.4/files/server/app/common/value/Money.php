<?php

declare(strict_types=1);

namespace app\common\value;

/** Exact application currency conversion at the persisted two-decimal boundary. */
final class Money
{
    public static function toCents(int|float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
