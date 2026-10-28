<?php

namespace App\Modules\Tontine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'un membre par le staff (R-MEMB-09) — sans PIN, défini à la 1ʳᵉ connexion (DA-12).
 */
class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => $this->filled('phone') ? preg_replace('/[\s.-]/', '', (string) $this->input('phone')) : null,
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'phone'      => ['required', 'string', 'regex:/^\+\d{7,15}$/', Rule::unique('users', 'phone')->ignore($this->memberId())],
            'full_name'  => ['required', 'string', 'min:2', 'max:150'],
            'email'      => ['nullable', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($this->memberId())],
            'birth_date' => ['nullable', 'date', 'before:-16 years', 'after:1900-01-01'],
            'locality'   => ['nullable', 'string', 'max:120'],
            // Création uniquement : false → compte « à activer » (connexion bloquée)
            'activate'   => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required'     => 'Le numéro de téléphone est obligatoire.',
            'phone.regex'        => 'Le numéro doit être au format international (+229…).',
            'phone.unique'       => 'Ce numéro de téléphone est déjà utilisé.',
            'full_name.required' => 'Le nom complet est obligatoire.',
            'full_name.min'      => 'Le nom doit contenir au moins 2 caractères.',
            'full_name.max'      => 'Le nom ne doit pas dépasser 150 caractères.',
            'email.email'        => 'L\'adresse email n\'est pas valide.',
            'email.unique'       => 'Cette adresse email est déjà utilisée.',
            'birth_date.date'    => 'La date de naissance n\'est pas valide.',
            'birth_date.before'  => 'Le membre doit avoir au moins 16 ans.',
            'birth_date.after'   => 'La date de naissance n\'est pas valide.',
            'locality.max'       => 'La localité ne doit pas dépasser 120 caractères.',
        ];
    }

    /** Id du membre modifié (null en création) — pour ignorer sa propre ligne dans les règles d'unicité. */
    protected function memberId(): ?string
    {
        return null;
    }
}
