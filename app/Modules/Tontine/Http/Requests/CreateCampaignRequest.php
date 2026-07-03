<?php

namespace App\Modules\Tontine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCampaignRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $mode = $this->input('contribution_mode', 'fixed');

        return [
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

            'duration_installments'         => ['required', 'integer', 'min:1', 'max:500'],
            'first_installment_date'        => ['required', 'date', 'after_or_equal:today'],
            'grace_period_days'             => ['sometimes', 'integer', 'min:0', 'max:30'],
            'min_membership_age_days'       => ['sometimes', 'integer', 'min:0'],
            'min_trust_score'               => ['sometimes', 'integer', 'min:0', 'max:100'],
            'maker_checker_threshold_minor' => ['sometimes', 'integer', 'min:0'],
            'max_members'                   => ['nullable', 'integer', 'min:1'],
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
            $min = $this->input('min_contribution_minor');
            $max = $this->input('max_contribution_minor');
            if ($min && $max && $min > $max) {
                $v->errors()->add('max_contribution_minor', 'Le montant maximum doit être supérieur au montant minimum.');
            }
        });
    }
}
