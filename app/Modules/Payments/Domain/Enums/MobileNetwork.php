<?php

namespace App\Modules\Payments\Domain\Enums;

/**
 * Réseau Mobile Money (DA-20) — INFORMATIF : réseau rapporté par l'agrégateur à la
 * vérification d'un encaissement, ou réseau du membre pour un versement.
 * Ce n'est jamais un moyen de paiement (voir `PaymentChannel`).
 */
enum MobileNetwork: string
{
    case Mtn     = 'mtn';
    case Moov    = 'moov';
    case Celtiis = 'celtiis';
    case Orange  = 'orange';
    case Wave    = 'wave';

    public function label(): string
    {
        return match ($this) {
            self::Mtn     => 'MTN MoMo',
            self::Moov    => 'Moov Money',
            self::Celtiis => 'Celtiis Cash',
            self::Orange  => 'Orange Money',
            self::Wave    => 'Wave',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * Devine le réseau depuis le libellé renvoyé par un agrégateur
     * (KKiaPay : « MTN », « MOOV »… ; FedaPay : `mtn_open`, `moov`, `sbin`…).
     */
    public static function fromPspMethod(?string $method): ?self
    {
        $m = strtolower((string) $method);

        return match (true) {
            $m === ''                                   => null,
            str_contains($m, 'mtn')                     => self::Mtn,
            str_contains($m, 'moov')                    => self::Moov,
            str_contains($m, 'sbin'), str_contains($m, 'celtiis') => self::Celtiis,
            str_contains($m, 'orange')                  => self::Orange,
            str_contains($m, 'wave')                    => self::Wave,
            default                                     => null,
        };
    }
}
