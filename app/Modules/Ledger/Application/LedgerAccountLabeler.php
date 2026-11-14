<?php

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Domain\Contracts\AccountOwnerLabelResolver;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;

/**
 * Libellés humains (français) des comptes ledger, là où la clé brute `TYPE:uuid`
 * était la seule information : « Caisse espèces », « MoMo MTN »,
 * « Épargne — Nom du membre · Campagne »…
 *
 * Les comptes dont le suffixe désigne une entité d'un autre module sont résolus
 * par les AccountOwnerLabelResolver enregistrés (DA-09). À défaut, repli sur le
 * libellé du type (+ référence courte).
 */
class LedgerAccountLabeler
{
    private const MOMO_LABELS = [
        'MTN'    => 'MoMo MTN',
        'MOOV'   => 'Moov Money',
        'ORANGE' => 'Orange Money',
        'WAVE'   => 'Wave',
    ];

    /** @var array<string, AccountOwnerLabelResolver> type => resolver */
    private array $resolvers = [];

    public function registerResolver(AccountType $type, AccountOwnerLabelResolver $resolver): void
    {
        $this->resolvers[$type->value] = $resolver;
    }

    /**
     * @param  string[]  $keys
     * @return array<string, string>  key → libellé
     */
    public function labelsForKeys(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (empty($keys)) {
            return [];
        }

        $types = LedgerAccount::whereIn('key', $keys)->pluck('type', 'key');

        return $this->labelsFor(array_map(fn ($key) => [
            'key'  => $key,
            'type' => $types[$key] ?? null,
        ], $keys));
    }

    /**
     * @param  iterable<LedgerAccount>  $accounts
     * @return array<string, string>  key → libellé
     */
    public function labelsForAccounts(iterable $accounts): array
    {
        $items = [];
        foreach ($accounts as $account) {
            $items[] = ['key' => $account->key, 'type' => $account->type];
        }

        return $this->labelsFor($items);
    }

    /** @param array<int, array{key: string, type: AccountType|string|null}> $items */
    private function labelsFor(array $items): array
    {
        // Regroupe les suffixes par type pour une requête par resolver.
        $owned = [];
        foreach ($items as $item) {
            $type = $this->type($item['type'], $item['key']);
            [, $suffix] = array_pad(explode(':', $item['key'], 2), 2, null);
            if ($type && $suffix && isset($this->resolvers[$type->value])) {
                $owned[$type->value][] = $suffix;
            }
        }

        $resolved = [];
        foreach ($owned as $typeValue => $ids) {
            $resolved[$typeValue] = $this->resolvers[$typeValue]->labels(array_values(array_unique($ids)));
        }

        $out = [];
        foreach ($items as $item) {
            $out[$item['key']] = $this->label($item['key'], $this->type($item['type'], $item['key']), $resolved);
        }

        return $out;
    }

    private function label(string $key, ?AccountType $type, array $resolved): string
    {
        [$prefix, $suffix] = array_pad(explode(':', $key, 2), 2, null);

        if ($type === AccountType::CashBox) {
            return 'Caisse espèces';
        }

        if ($type === AccountType::PspFloat) {
            $psp = \App\Modules\Payments\Domain\Enums\PaymentChannel::tryFrom(strtolower((string) $suffix));
            return 'Solde ' . ($psp?->label() ?? strtoupper((string) $suffix));
        }

        if ($type === AccountType::MomoFloat) {
            $operator = strtoupper((string) $suffix);
            return (self::MOMO_LABELS[$operator] ?? ('Mobile Money ' . ($operator ?: ''))) . ' (ancien)';
        }

        if ($type && $suffix && isset($resolved[$type->value][$suffix])) {
            return $resolved[$type->value][$suffix];
        }

        $base = $type?->label() ?? $prefix;

        return $suffix ? $base . ' · ' . strtoupper(substr($suffix, 0, 8)) : $base;
    }

    private function type(AccountType|string|null $type, string $key): ?AccountType
    {
        if ($type instanceof AccountType) {
            return $type;
        }

        return AccountType::tryFrom((string) ($type ?? explode(':', $key, 2)[0]));
    }
}
