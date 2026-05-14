<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class ZohoEventFormWebhookRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['payload' => ['sometimes', 'array']];
    }

    public function validationData(): array
    {
        return ['payload' => $this->all()] + $this->all();
    }
}
