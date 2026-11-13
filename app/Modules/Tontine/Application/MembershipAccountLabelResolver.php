<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Ledger\Domain\Contracts\AccountOwnerLabelResolver;
use App\Modules\Tontine\Infrastructure\Models\Membership;

/** Libellé des comptes MEMBER_SAVINGS:{membership_id} : « Épargne — Nom · Campagne ». */
class MembershipAccountLabelResolver implements AccountOwnerLabelResolver
{
    public function labels(array $ids): array
    {
        $uuids = array_values(array_filter($ids, fn ($id) => preg_match('/^[0-9a-f-]{36}$/i', $id)));
        if (empty($uuids)) {
            return [];
        }

        return Membership::with(['user:id,full_name', 'campaign:id,name'])
            ->whereIn('id', $uuids)
            ->get(['id', 'user_id', 'campaign_id'])
            ->mapWithKeys(fn (Membership $m) => [
                $m->id => 'Épargne — ' . ($m->user?->full_name ?? 'Membre') . ' · ' . ($m->campaign?->name ?? 'Campagne'),
            ])
            ->all();
    }
}
