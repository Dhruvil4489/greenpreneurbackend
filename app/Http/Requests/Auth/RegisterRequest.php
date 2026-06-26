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

        $mainBusinessCategoryId = $this->nullableInput(
            'main_business_category_id',
            $this->nullableInput('business_category_main_id', $level1)
        );
        $businessCategoryId = $this->filled('business_category_id')
            ? $this->nullableInput('business_category_id')
            : $this->nullableInput('business_category_sub_id', $level4);

        $companyName = $this->nullableInput('company_name', $this->nullableInput('business_name'));
        $designation = $this->nullableInput('designation', $this->nullableInput('position'));
        $referredByUserId = $this->nullableInput('referred_by_user_id', $this->nullableInput('invited_by_user_id'));

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
            'company_name' => $companyName,
            'designation' => $designation,
            'referred_by_user_id' => $referredByUserId,
            'city_id' => $this->nullableInput('city_id'),
        ];

        $payload['referral_code'] = blank($incomingReferralCode)
            ? null
            : strtoupper(trim((string) $incomingReferralCode));

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

            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],

            // PHONE IS REQUIRED + UNIQUE TO AVOID DB UNIQUE VIOLATION
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],

            // PASSWORD WITH CONFIRMATION (OPTIONAL FOR OTP-ONLY REGISTRATION)
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],

            // NEW OPTIONAL FIELDS FOR REGISTRATION
            'business_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'designation'  => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'uuid', 'exists:cities,id'],
            'city' => ['nullable', 'string', 'max:255'],
            'city_of_residence' => ['nullable', 'string', 'max:150'],
            'state' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:150'],
            'country' => ['nullable', 'string', 'max:100'],
            'business_category_main_id' => ['nullable', 'integer', 'exists:circle_categories,id'],
            'business_category_sub_id' => ['nullable', 'integer', Rule::exists($this->level4CategoriesTable(), 'id')],
            'main_business_category_id' => ['nullable', 'integer', 'exists:circle_categories,id'],
            'business_category_id' => ['nullable', 'integer', Rule::exists($this->level4CategoriesTable(), 'id')],
            'business_website' => ['nullable', 'url', 'max:500'],
            'business_description' => ['nullable', 'string', 'max:5000'],
            'company_address' => ['nullable', 'string', 'max:1000'],
            'whatsapp_number' => ['nullable', 'string', 'max:30'],
            'linkedin_url' => ['nullable', 'url', 'max:500'],
            'instagram_url' => ['nullable', 'url', 'max:500'],
            'facebook_url' => ['nullable', 'url', 'max:500'],
            'invited_by_user_id' => ['nullable', 'uuid', 'exists:users,id'],
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
            'website' => ['nullable', 'url', 'max:255'],
            'sustainability_contribution' => ['nullable', 'string'],
            'sustainability_areas' => ['nullable', 'array'],
            'greenpreneur_goals' => ['nullable', 'array'],
            'interests' => ['nullable', 'array'],
            'community_directory_listing' => ['sometimes', 'required', 'in:Yes,No'],
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
            'profile_photo.image' => 'The profile photo must be an image file.',
            'profile_photo.mimes' => 'The profile photo must be a JPG, JPEG, PNG, or WEBP image.',
            'profile_photo.max' => 'The profile photo may not be greater than 2 MB.',
            'referral_code.regex' => 'Referral code format is invalid.',
        ];
    }
}
