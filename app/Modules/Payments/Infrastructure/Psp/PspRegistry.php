<?php

namespace App\Modules\Payments\Infrastructure\Psp;

use App\Modules\Payments\Domain\Contracts\PspDriver;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Agrégateurs de paiement disponibles (DA-20). Un agrégateur est utilisable par les
 * membres s'il est configuré (clés présentes) ET activé dans les paramètres système
 * (`psp_{agrégateur}_enabled`, page « Moyens de paiement » du back-office).
 */
class PspRegistry
{
    /** @param array<string, PspDriver> $drivers  valeur PaymentChannel → driver */
    public function __construct(private readonly array $drivers) {}

    public function driver(PaymentChannel|string $channel): PspDriver
    {
        $key = $channel instanceof PaymentChannel ? $channel->value : $channel;
        if (! isset($this->drivers[$key])) {
            throw new InvalidArgumentException("Aucun agrégateur de paiement pour « {$key} ».");
        }

        return $this->drivers[$key];
    }

    public function has(PaymentChannel|string $channel): bool
    {
        return isset($this->drivers[$channel instanceof PaymentChannel ? $channel->value : $channel]);
    }

    /** @return array<string, PspDriver> */
    public function all(): array
    {
        return $this->drivers;
    }

    public function isEnabled(PaymentChannel $channel): bool
    {
        $value = DB::table('system_settings')->where('key', $channel->settingKey())->value('value');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Configuré et activé : le membre peut cotiser avec. */
    public function isAvailable(PaymentChannel $channel): bool
    {
        return $channel->isPsp() && $this->has($channel)
            && $this->driver($channel)->isConfigured() && $this->isEnabled($channel);
    }

    /** @return list<PaymentChannel> */
    public function available(): array
    {
        return array_values(array_filter(PaymentChannel::psps(), fn (PaymentChannel $c) => $this->isAvailable($c)));
    }

    /** Description d'un agrégateur pour les fronts (sans aucun secret). */
    public function describe(PaymentChannel $channel): array
    {
        $driver = $this->driver($channel);

        return [
            'channel'    => $channel->value,
            'label'      => $channel->label(),
            'networks'   => $driver->networks(),
            'mode'       => $channel === PaymentChannel::Fedapay ? 'redirect' : 'widget',
            'sandbox'    => $driver->isSandbox(),
            'configured' => $driver->isConfigured(),
            'enabled'    => $this->isEnabled($channel),
        ];
    }
}
