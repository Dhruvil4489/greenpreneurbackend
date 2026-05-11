<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $incomingReferralCode = $this->input('referral_code');

        if (blank($incomingReferralCode) && $this->has('referralCode')) {
            $incomingReferralCode = $this->input('referralCode');
        }

        $level1 = $this->nullableInput('level_1_category_id', $this->input('level1_category_id'));
        $level2 = $this->nullableInput('level_2_category_id', $this->input('level2_category_id'));
        $level3 = $this->nullableInput('level_3_category_id', $this->input('level3_category_id'));
        $level4 = $this->nullableInput('level_4_category_id', $this->input('level4_category_id', $this->input('category_id')));

        $mainBusinessCategoryId = $this->nullableInput('main_business_category_id', $level1);
        $businessCategoryId = $this->filled('business_category_id')
            ? $this->nullableInput('business_category_id')
            : $level4;

        $payload = [
            'level_1_category_id' => $level1,
            'level_2_category_id' => $level2,
            'level_3_category_id' => $level3,
            'level_4_category_id' => $level4,
            // keep legacy keys populated for backward compatibility with existing code paths
            'level1_category_id' => $level1,
            'level2_category_id' => $level2,
            'level3_category_id' => $level3,
            'level4_category_id' => $level4,
            'main_business_category_id' => $mainBusinessCategoryId,
            'business_category_id' => $businessCategoryId,
            'referred_by_user_id' => $this->nullableInput('referred_by_user_id'),
            'city_id' => $this->nullableInput('city_id'),
        ];

        if (! blank($incomingReferralCode)) {
            $payload['referral_code'] = strtoupper(trim((string) $incomingReferralCode));
        }

        $this->merge($payload);
    }

    private function nullableInput(string $key, mixed $default = null): mixed
    {
        $value = $this->input($key, $default);

        return $value === '' ? null : $value;
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:150'],

            'email' => ['required', 'email:rfc,dns', 'max:255', 'unique:users,email'],

            // PHONE IS REQUIRED + UNIQUE TO AVOID DB UNIQUE VIOLATION
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],

            // PASSWORD WITH CONFIRMATION (OPTIONAL FOR OTP-ONLY REGISTRATION)
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],

            // NEW OPTIONAL FIELDS FOR REGISTRATION
            'company_name' => ['nullable', 'string', 'max:255'],
            'designation'  => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'uuid', 'exists:cities,id'],
            'city_of_residence' => ['nullable', 'string', 'max:150'],
            'main_business_category_id' => ['nullable', 'integer', 'exists:circle_categories,id'],
            'business_category_id' => ['nullable', 'integer', Rule::exists($this->level4CategoriesTable(), 'id')],
            'referred_by_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'circle_id' => ['nullable', 'uuid', 'exists:circles,id'],
            'level_1_category_id' => ['nullable', 'integer', 'exists:circle_categories,id'],
            'level_2_category_id' => ['nullable', 'integer', Rule::exists($this->level2CategoriesTable(), 'id')],
            'level_3_category_id' => ['nullable', 'integer', Rule::exists($this->level3CategoriesTable(), 'id')],
            'level_4_category_id' => ['nullable', 'integer', Rule::exists($this->level4CategoriesTable(), 'id')],
            'referral_code' => [
                'nullable',
                'string',
                'max:32',
                'regex:/^[A-Z0-9]{8,32}$/',
            ],
        ];
    }



    private function level2CategoriesTable(): string
    {
        return $this->firstExistingCategoryTable(['level2_categories', 'circle_category_level2']);
    }

    private function level3CategoriesTable(): string
    {
        return $this->firstExistingCategoryTable(['level3_categories', 'circle_category_level3']);
    }

    private function level4CategoriesTable(): string
    {
        return $this->firstExistingCategoryTable(['level4_categories', 'circle_category_level4']);
    }

    private function firstExistingCategoryTable(array $tables): string
    {
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return $tables[0];
    }

    public function messages(): array
    {
        return [
            'phone.unique' => 'This phone number is already registered.',
            'referral_code.regex' => 'Referral code format is invalid.',
        ];
    }
}
