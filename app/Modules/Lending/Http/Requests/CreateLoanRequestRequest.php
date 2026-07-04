<?php

namespace App\Modules\Lending\Http\Requests;

use App\Modules\Lending\Domain\Enums\LoanPeriodicity;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation polymorphe pour la création d'une demande de prêt.
 *
 * - `advance` : membership_id requis (cible), campaign_id inféré. Aucun champ
 *   de schedule.
 * - `standard` : membership_id requis (cible), campaign_id optionnel,
 *   periodicity + soit (installments_count + first_due_date) soit
 *   (custom_due_dates non vide).
 */
class CreateLoanRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_type'       => ['required', Rule::in(array_column(LoanProductType::cases(), 'value'))],
            'membership_id'      => ['required', 'uuid', 'exists:memberships,id'],
            'campaign_id'        => ['nullable', 'uuid', 'exists:tontine_campaigns,id'],
            'amount_minor'       => ['required', 'integer', 'min:1'],
            'purpose'            => ['nullable', 'string', 'max:500'],
            'interest_rate_bps'  => ['nullable', 'integer', 'min:0', 'max:100000'],
            'periodicity'        => ['nullable', Rule::in(array_column(LoanPeriodicity::cases(), 'value'))],
            'installments_count' => ['nullable', 'integer', 'min:1', 'max:120'],
            'first_due_date'     => ['nullable', 'date', 'after_or_equal:today'],
            'custom_due_dates'   => ['nullable', 'array', 'min:1', 'max:120'],
            'custom_due_dates.*' => ['date', 'after_or_equal:today'],
        ];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            $type = $this->input('product_type');
            if ($type !== LoanProductType::Standard->value) return;

            $periodicity = $this->input('periodicity');
            if (! $periodicity) {
                $v->errors()->add('periodicity', 'La périodicité est requise pour un prêt standard.');
                return;
            }

            if ($periodicity === LoanPeriodicity::Custom->value) {
                if (empty($this->input('custom_due_dates'))) {
                    $v->errors()->add('custom_due_dates', 'Au moins une date d\'échéance est requise pour une périodicité personnalisée.');
                }
            } else {
                if (! $this->input('installments_count')) {
                    $v->errors()->add('installments_count', 'Le nombre d\'échéances est requis.');
                }
                if (! $this->input('first_due_date')) {
                    $v->errors()->add('first_due_date', 'La date de la première échéance est requise.');
                }
            }
        });
    }
}
