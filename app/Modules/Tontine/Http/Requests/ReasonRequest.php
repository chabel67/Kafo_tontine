<?php

namespace App\Modules\Tontine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Motif obligatoire (≥ 10 caractères) des actions sensibles : suspension d'un compte (R-MEMB-05),
 * refus d'un retrait anticipé.
 */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Le motif est obligatoire.',
            'reason.min'      => 'Le motif doit contenir au moins 10 caractères.',
            'reason.max'      => 'Le motif ne doit pas dépasser 500 caractères.',
        ];
    }
}
