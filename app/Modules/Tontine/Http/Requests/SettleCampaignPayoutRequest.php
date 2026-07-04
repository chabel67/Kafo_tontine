<?php

namespace App\Modules\Tontine\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SettleCampaignPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', 'in:cash,mtn,orange,moov,wave'],
            'phone'   => ['nullable', 'string', 'regex:/^\+\d{7,15}$/'],
        ];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v) {
            if ($this->input('channel') !== 'cash' && ! $this->input('phone')) {
                $v->errors()->add('phone', 'Le téléphone est requis pour un versement Mobile Money.');
            }
        });
    }
}
