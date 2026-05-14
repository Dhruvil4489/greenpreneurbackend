<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class ScanEventQrRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'max:512'],
            'scanner_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
