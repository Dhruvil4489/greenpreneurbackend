<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class VisitorEventRegistrationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'visitor_name' => ['required', 'string', 'max:255'],
            'visitor_email' => ['nullable', 'email', 'max:255'],
            'visitor_phone' => ['required', 'string', 'min:6', 'max:50', 'regex:/^[0-9+()\-\s]+$/'],
            'visitor_company' => ['nullable', 'string', 'max:255'],
            'visitor_city' => ['nullable', 'string', 'max:255'],
            'zoho_form_entry_id' => ['nullable', 'string', 'max:255'],
            'zoho_payment_id' => ['nullable', 'string', 'max:255'],
            'zoho_payment_status' => ['nullable', 'string', 'max:100'],
            'source' => ['sometimes', 'string', 'in:app,visitor_app,visitor_web,admin,zoho_form'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
