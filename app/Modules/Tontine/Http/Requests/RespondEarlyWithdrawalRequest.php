<?php

namespace App\Modules\Tontine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RespondEarlyWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'response.required' => 'Votre réponse est obligatoire.',
            'response.min'      => 'Votre réponse doit contenir au moins 3 caractères.',
        ];
    }
}
