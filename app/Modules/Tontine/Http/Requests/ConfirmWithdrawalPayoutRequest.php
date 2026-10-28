<?php

namespace App\Modules\Tontine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmWithdrawalPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Référence de l'opérateur MoMo ou n° de reçu de caisse
            'external_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
