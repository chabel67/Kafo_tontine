<?php

namespace App\Modules\Tontine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCampaignRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $mode     = $this->input('contribution_mode', 'fixed');
        $rotating = $this->input('campaign_type') === 'rotating';
        $custom   = ($this->input('contribution_schedule.type')) === 'custom_dates';

        return [
            // Lot 2 rotatif : type de campagne et paramètres du calendrier (D-R1, D-R5).
            'campaign_type'                 => ['sometimes', 'string', 'in:savings,rotating'],
            'installments_per_round'        => [$rotating ? 'required' : 'prohibited', 'integer', 'min:1', 'max:366'],
            'contribution_schedule'         => [$rotating ? 'required' : 'prohibited', 'array'],
            'rotation_fee_mode'             => [$rotating ? 'sometimes' : 'prohibited', 'string', 'in:none,payout_deduction,contribution_surcharge'],
            'rotation_fee_minor'            => [$rotating ? 'sometimes' : 'prohibited', 'integer', 'min:0', 'max:100000000'],
            'name'                          => ['required', 'string', 'max:150'],
            'description'                   => ['nullable', 'string'],
            'market_calendar_id'            => ['nullable', 'integer', 'exists:market_calendars,id'],
            'contribution_mode'             => ['sometimes', 'string', 'in:fixed,custom'],

            // Montant fixe : requis seulement en mode fixed
            'contribution_amount_minor'     => [
                $mode === 'fixed' ? 'required' : 'prohibited',
                'integer', 'min:1',
            ],

            // Bornes custom : uniquement en mode custom
            'min_contribution_minor'        => [
                $mode === 'custom' ? 'nullable' : 'prohibited',
                'integer', 'min:1',
            ],
            'max_contribution_minor'        => [
                $mode === 'custom' ? 'nullable' : 'prohibited',
                'integer', 'min:1',
            ],

            // Rotatif : nombre d'échéances déduit (participants × k) ; date de départ facultative pour des dates manuelles.
            'duration_installments'         => [$rotating ? 'prohibited' : 'required', 'integer', 'min:1', 'max:500'],
            'first_installment_date'        => [$rotating && $custom ? 'nullable' : 'required', 'date', 'after_or_equal:today'],
            'grace_period_days'             => ['sometimes', 'integer', 'min:0', 'max:30'],
            'min_membership_age_days'       => ['sometimes', 'integer', 'min:0'],
            'min_trust_score'               => ['sometimes', 'integer', 'min:0', 'max:100'],
            'maker_checker_threshold_minor' => ['sometimes', 'integer', 'min:0'],
            'max_members'                   => [$rotating ? 'required' : 'nullable', 'integer', $rotating ? 'min:2' : 'min:1', 'max:500'],
            // R-WDR-08 : retraits anticipés autorisés (par défaut oui).
            'allow_early_withdrawals'       => ['sometimes', 'boolean'],
            // R-FEE-01 : frais d'adhésion (0 = aucun), distincts des cotisations et de l'épargne.
            'membership_fee_minor'          => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'contribution_amount_minor.required' => 'Le montant de cotisation est obligatoire en mode cotisation fixe.',
            'contribution_amount_minor.prohibited' => 'Le montant fixe ne doit pas être renseigné en mode cotisation personnalisée.',
            'min_contribution_minor.prohibited'  => 'Les bornes de montant sont uniquement disponibles en mode cotisation personnalisée.',
            'max_contribution_minor.prohibited'  => 'Les bornes de montant sont uniquement disponibles en mode cotisation personnalisée.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('campaign_type') === 'rotating') {
                if ($this->input('contribution_mode', 'fixed') !== 'fixed') {
                    $v->errors()->add('contribution_mode', 'Une tontine rotative exige une cotisation fixe (D-R1).');
                }
                foreach (app(\App\Modules\Tontine\Application\ScheduleCalculator::class)->validate($this->input('contribution_schedule')) as $field => $message) {
                    $v->errors()->add($field, $message);
                }
                if (($this->input('market_calendar_id')) && ($this->input('contribution_schedule.type')) !== 'market_calendar') {
                    $v->errors()->add('market_calendar_id', 'Pour une tontine rotative, le calendrier de marché se choisit dans la fréquence.');
                }
            }
            $min = $this->input('min_contribution_minor');
            $max = $this->input('max_contribution_minor');
            if ($min && $max && $min > $max) {
                $v->errors()->add('max_contribution_minor', 'Le montant maximum doit être supérieur au montant minimum.');
            }
        });
    }
}
