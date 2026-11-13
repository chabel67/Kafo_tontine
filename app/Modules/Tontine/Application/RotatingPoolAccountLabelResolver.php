<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Ledger\Domain\Contracts\AccountOwnerLabelResolver;
use App\Modules\Tontine\Infrastructure\Models\Campaign;

/** Libellé des comptes ROTATING_POOL:{campaign_id} : « Caisse tontine — Campagne ». */
class RotatingPoolAccountLabelResolver implements AccountOwnerLabelResolver
{
    public function labels(array $ids): array
    {
        $uuids = array_values(array_filter($ids, fn ($id) => preg_match('/^[0-9a-f-]{36}$/i', $id)));
        if (empty($uuids)) {
            return [];
        }

        return Campaign::whereIn('id', $uuids)->pluck('name', 'id')
            ->map(fn ($name) => "Caisse tontine — {$name}")
            ->all();
    }
}
