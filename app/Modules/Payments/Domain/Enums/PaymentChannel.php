<?php

namespace App\Modules\Payments\Domain\Enums;

/**
 * Moyen d'encaissement ou de versement (DA-20, 2026-11-14).
 *
 * Un moyen de paiement est un AGRÉGATEUR (KKiaPay, FedaPay) ou les espèces — jamais un
 * réseau. Le réseau (MTN, Moov, Celtiis…) est choisi dans le widget de l'agrégateur et
 * noté pour information (`MobileNetwork`). Le solde réel est chez l'agrégateur :
 * compte de trésorerie `PSP_FLOAT:{AGRÉGATEUR}`.
 *
 * Les anciennes valeurs (mtn, moov, orange, wave) restent dans l'historique des
 * versements ; elles ne sont plus acceptées en entrée (`legacyLabel()` pour l'affichage).
 */
enum PaymentChannel: string
{
    case Cash    = 'cash';
    case Kkiapay = 'kkiapay';
    case Fedapay = 'fedapay';

    /** Anciennes valeurs « réseau » (avant DA-20), affichées dans l'historique. */
    public const LEGACY_NETWORK_VALUES = ['mtn', 'moov', 'orange', 'wave'];

    public function label(): string
    {
        return match ($this) {
            self::Cash    => 'Espèces',
            self::Kkiapay => 'KKiaPay',
            self::Fedapay => 'FedaPay',
        };
    }

    /** Agrégateur de paiement (tout sauf les espèces). */
    public function isPsp(): bool
    {
        return $this !== self::Cash;
    }

    /** Alias historique : un agrégateur encaisse du Mobile Money. */
    public function isMomo(): bool
    {
        return $this->isPsp();
    }

    /** Clé du paramètre système d'activation (`system_settings`, groupe psp). */
    public function settingKey(): string
    {
        return "psp_{$this->value}_enabled";
    }

    /** @return list<self> */
    public static function psps(): array
    {
        return [self::Kkiapay, self::Fedapay];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public static function fromSettingKey(string $key): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->settingKey() === $key) {
                return $case;
            }
        }

        return null;
    }

    /** Libellé d'une valeur stockée, y compris les anciennes valeurs réseau. */
    public static function labelFor(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if ($case = self::tryFrom($value)) {
            return $case->label();
        }
        $network = MobileNetwork::tryFrom($value);

        return ($network?->label() ?? strtoupper($value)) . ' (ancien)';
    }
}
