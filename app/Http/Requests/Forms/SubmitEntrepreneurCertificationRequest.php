<?php

namespace App\Http\Requests\Forms;

use App\Models\EntrepreneurCertificationSubmission;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SubmitEntrepreneurCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [
            'full_name' => $this->trimValue($this->input('full_name')),
            'business_name' => $this->trimValue($this->input('business_name')),
            'email' => $this->trimValue($this->input('email')),
            'contact_no' => $this->trimValue($this->input('contact_no')),
        ];

        foreach (EntrepreneurCertificationSubmission::QUIZ_FIELDS as $field) {
            $trimmed[$field] = $this->trimValue($this->input($field));
        }

        $this->merge($trimmed);
    }

    public function rules(): array
    {
        $rules = [
            'full_name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'contact_no' => ['required', 'string', 'max:30'],
        ];

        foreach (EntrepreneurCertificationSubmission::QUIZ_FIELDS as $field) {
            $rules[$field] = ['required', 'string'];
        }

        return $rules;
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422));
    }

    private function trimValue(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}
