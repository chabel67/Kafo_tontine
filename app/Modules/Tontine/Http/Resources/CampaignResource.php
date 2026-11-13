<?php

namespace App\Modules\Tontine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $memberCount = $this->memberships_count
            ?? $this->whenLoaded('memberships', fn () => $this->memberships->count(), null);

        return [
            'id'                            => $this->id,
            'name'                          => $this->name,
            'description'                   => $this->description,
            'status'                        => $this->status?->value,
            'status_label'                  => $this->status?->label(),
            'currency'                      => $this->currency,
            'contribution_mode'             => $this->contribution_mode?->value,
            'contribution_mode_label'       => $this->contribution_mode?->label(),
            'contribution_amount_minor'     => $this->contribution_amount_minor,
            'min_contribution_minor'        => $this->min_contribution_minor,
            'max_contribution_minor'        => $this->max_contribution_minor,
            'duration_installments'         => $this->duration_installments,
            'first_installment_date'        => $this->first_installment_date?->toDateString(),
            'grace_period_days'             => $this->grace_period_days,
            'min_membership_age_days'       => $this->min_membership_age_days,
            'min_trust_score'               => $this->min_trust_score,
            'maker_checker_threshold_minor' => $this->maker_checker_threshold_minor,
            'max_members'                   => $this->max_members,
            'allow_early_withdrawals'       => (bool) $this->allow_early_withdrawals,
            // Reconduction : série de cycles (R1).
            'series_id'                     => $this->series_id,
            'cycle_number'                  => (int) ($this->cycle_number ?? 1),
            'previous_campaign_id'          => $this->previous_campaign_id,
            'next_campaign_id'              => $this->resource->nextCycle()->value('id'),
            // Campagnes rotatives (lot 1) : `savings` par défaut pour l'existant.
            'campaign_type'                 => $this->campaign_type?->value ?? 'savings',
            'campaign_type_label'           => $this->campaign_type?->label() ?? 'Épargne',
            'installments_per_round'        => $this->installments_per_round,
            'rotation_fee_mode'             => $this->rotation_fee_mode?->value ?? 'none',
            'rotation_fee_minor'            => (int) $this->rotation_fee_minor,
            'contribution_schedule'         => $this->contribution_schedule,
            'schedule_confirmed_at'         => $this->schedule_confirmed_at?->toIso8601String(),
            'started_at'                    => $this->started_at?->toIso8601String(),
            'membership_fee_minor'          => (int) $this->membership_fee_minor,
            'member_count'                  => $memberCount,
            'market_calendar'               => $this->whenLoaded('marketCalendar', fn () => [
                'id'           => $this->marketCalendar->id,
                'name'         => $this->marketCalendar->name,
                'cadence_type' => $this->marketCalendar->cadence_type?->value,
            ]),
            // Fiche campagne uniquement (GET /admin/campaigns/{id}) — voir CampaignFinancialsService.
            'financials'   => $this->when(
                $this->resource->getAttribute('financials') !== null,
                fn () => $this->resource->getAttribute('financials'),
            ),
            'opened_at'    => $this->opened_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'closed_at'    => $this->closed_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
