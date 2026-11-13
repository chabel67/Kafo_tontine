<?php

namespace App\Modules\Payments\Domain\Enums;

enum PaymentChannel: string
{
    case Cash   = 'cash';
    case Mtn    = 'mtn';
    case Orange = 'orange';
    case Moov   = 'moov';
    case Wave   = 'wave';

    public function label(): string
    {
        return match($this) {
            self::Cash   => 'Espèces',
            self::Mtn    => 'MTN MoMo',
            self::Orange => 'Orange Money',
            self::Moov   => 'Moov Money',
            self::Wave   => 'Wave',
        };
    }

    public function isMomo(): bool
    {
        return $this !== self::Cash;
    }

    /**
     * DA-13 : canal Mobile Money réellement routé par l'agrégateur KKiaPay (MTN, Moov).
     * Orange et Wave restent dans l'enum (historique, données existantes) mais ne peuvent
     * ni être activés ni être initiés par un membre.
     */
    public function isRoutedByPsp(): bool
    {
        return in_array($this, [self::Mtn, self::Moov], true);
    }

    /** Clé du paramètre système d'activation (`system_settings`, groupe psp). */
    public function settingKey(): string
    {
        return "psp_{$this->value}_enabled";
    }

    /** Canal disponible sur la plateforme : espèces (trésorier) ou MoMo routé par le PSP. */
    public function isSupported(): bool
    {
        return $this === self::Cash || $this->isRoutedByPsp();
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
}
