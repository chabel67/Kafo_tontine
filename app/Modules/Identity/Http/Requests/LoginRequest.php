<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+\d{7,15}$/'],
            'otp'   => ['required', 'string', 'digits:6'],
            'pin'   => ['required', 'string', 'digits:6'],
        ];
    }
}
