<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class RegisterEventOccurrenceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['source' => ['sometimes', 'string', 'in:app,admin,scanner,zoho_form']];
    }
}
