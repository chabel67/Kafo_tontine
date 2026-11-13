<?php

namespace App\Shared;

/**
 * Masquage d'un numéro de téléphone pour les journaux (CLAUDE.md §4.6 : jamais
 * de numéro complet en log). Conserve l'indicatif et les 2 derniers chiffres.
 */
final class PhoneMask
{
    public static function mask(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return $phone;
        }

        $length = strlen($phone);
        if ($length <= 6) {
            return str_repeat('•', $length);
        }

        return substr($phone, 0, 4) . str_repeat('•', $length - 6) . substr($phone, -2);
    }
}
