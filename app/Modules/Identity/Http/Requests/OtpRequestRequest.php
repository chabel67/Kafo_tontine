<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OtpRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone'   => ['required', 'string', 'regex:/^\+\d{7,15}$/'],
            'purpose' => ['sometimes', 'string', 'in:login,signup,step_up,phone_change'],
        ];
    }
}
