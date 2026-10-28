<?php

namespace App\Modules\Tontine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateEarlyWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'membership_id'   => ['required', 'uuid'],
            'amount_minor'    => ['required', 'integer', 'min:1'],
            'reason'          => ['nullable', 'string', 'max:500'],
            'payout_channel'  => ['required', 'in:cash,mtn,moov,orange,wave'],
            'payout_phone'    => ['nullable', 'required_unless:payout_channel,cash', 'string', 'regex:/^\+\d{7,15}$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'membership_id.required' => 'Choisissez la campagne concernée.',
            'amount_minor.required'  => 'Le montant est obligatoire.',
            'amount_minor.integer'   => 'Le montant doit être un nombre entier de FCFA.',
            'amount_minor.min'       => 'Le montant doit être supérieur à 0.',
            'reason.max'             => 'Le motif ne doit pas dépasser 500 caractères.',
            'payout_channel.required' => 'Choisissez le moyen de réception.',
            'payout_channel.in'       => 'Moyen de réception non pris en charge.',
            'payout_phone.required_unless' => 'Le numéro Mobile Money est obligatoire pour un versement MoMo.',
            'payout_phone.regex'      => 'Le numéro doit être au format international (+229…).',
        ];
    }
}
