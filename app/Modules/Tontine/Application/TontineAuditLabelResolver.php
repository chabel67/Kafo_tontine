<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Audit\Domain\Contracts\AuditEntityLabelResolver;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Membership;

/**
 * Libellés des entités d'audit du module Tontine :
 *  - tontine_campaign : « Nom de la campagne » ;
 *  - membership       : « Adhésion — Nom · Campagne » ;
 *  - campaign_payout  : « Versement R-… — Nom » ;
 *  - early_withdrawal : « Retrait WDR-… — Nom ».
 */
class TontineAuditLabelResolver implements AuditEntityLabelResolver
{
    public const ENTITY_TYPES = ['tontine_campaign', 'membership', 'campaign_payout', 'early_withdrawal'];

    public function labels(string $entityType, array $ids): array
    {
        return match ($entityType) {
            'tontine_campaign' => Campaign::whereIn('id', $ids)->pluck('name', 'id')->all(),

            'membership' => Membership::with(['user:id,full_name', 'campaign:id,name'])
                ->whereIn('id', $ids)->get(['id', 'user_id', 'campaign_id'])
                ->mapWithKeys(fn (Membership $m) => [
                    $m->id => 'Adhésion — ' . ($m->user?->full_name ?? 'Membre') . ' · ' . ($m->campaign?->name ?? 'Campagne'),
                ])->all(),

            'campaign_payout' => CampaignPayout::with('membership.user:id,full_name')
                ->whereIn('id', $ids)->get(['id', 'reference', 'membership_id'])
                ->mapWithKeys(fn (CampaignPayout $p) => [
                    $p->id => 'Versement ' . $p->reference . ' — ' . ($p->membership?->user?->full_name ?? 'Membre'),
                ])->all(),

            'early_withdrawal' => EarlyWithdrawal::with('user:id,full_name')
                ->whereIn('id', $ids)->get(['id', 'reference', 'user_id'])
                ->mapWithKeys(fn (EarlyWithdrawal $w) => [
                    $w->id => 'Retrait ' . $w->reference . ' — ' . ($w->user?->full_name ?? 'Membre'),
                ])->all(),

            default => [],
        };
    }
}
