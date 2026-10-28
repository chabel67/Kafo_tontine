<?php

namespace App\Modules\Tontine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'La note ne peut pas être vide.',
            'body.min'      => 'La note doit contenir au moins 3 caractères.',
            'body.max'      => 'La note ne doit pas dépasser 2000 caractères.',
        ];
    }
}
