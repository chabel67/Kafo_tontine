<?php

namespace App\Shared;

/**
 * Formatage des montants Kafo (CLAUDE.md §9.3) : séparateur de milliers = espace
 * insécable (U+00A0), devise explicite « FCFA ». Ex. 120000 → « 120 000 FCFA ».
 * Les montants sont toujours des entiers en unités mineures XOF (DA-08).
 */
final class Money
{
    public const NBSP = "\u{00A0}";

    public static function format(?int $amountMinor, string $currency = 'FCFA'): string
    {
        $amount = number_format((int) $amountMinor, 0, ',', self::NBSP);

        return $amount . self::NBSP . $currency;
    }
}
