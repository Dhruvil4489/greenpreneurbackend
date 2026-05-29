<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class VisitorEventRegistrationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'visitor_name' => $this->input('visitor_name', $this->input('full_name')),
            'visitor_email' => $this->input('visitor_email', $this->input('email')),
            'visitor_phone' => $this->input('visitor_phone', $this->input('phone')),
            'visitor_company' => $this->input('visitor_company', $this->input('company_name')),
            'visitor_city' => $this->input('visitor_city', $this->input('city')),
        ], fn ($value) => $value !== null));
    }

    public function rules(): array
    {
        return [
            'visitor_name' => ['required', 'string', 'max:150'],
            'visitor_email' => ['nullable', 'email', 'max:150'],
            'visitor_phone' => ['required', 'string', 'max:20'],
            'visitor_company' => ['nullable', 'string', 'max:120'],
            'visitor_city' => ['nullable', 'string', 'max:120'],
            'visitor_designation' => ['nullable', 'string', 'max:150'],
            'visitor_business_category_id' => $this->visitorBusinessCategorySubIdRules(),
            'visitor_business_category' => ['nullable', 'string', 'max:150'],
            'visitor_business_category_main_id' => ['nullable', 'integer', Rule::exists('circle_categories', 'id')],
            'visitor_business_category_sub_id' => $this->visitorBusinessCategorySubIdRules(),
            'visitor_business_website' => ['nullable', 'url', 'max:255'],
            'visitor_business_brief' => ['nullable', 'string', 'max:2000'],
            'invited_by_type' => ['nullable', 'string', Rule::in(['peers_global_team', 'circle_member_peer', 'other'])],
            'invited_by_user_id' => ['nullable', 'uuid', 'exists:users,id', 'required_if:invited_by_type,circle_member_peer,other'],
            'full_name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_category_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_sub_category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'referral_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'referred_by' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'zoho_form_entry_id' => ['nullable', 'string', 'max:255'],
            'zoho_payment_id' => ['nullable', 'string', 'max:255'],
            'zoho_payment_status' => ['nullable', 'string', 'max:100'],
            'source' => ['sometimes', 'string', 'in:app,visitor_app,visitor_web,admin,zoho_form'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function visitorBusinessCategorySubIdRules(): array
    {
        $rules = ['nullable', 'integer'];

        $table = $this->level4CategoriesTable();
        if ($table) {
            $rules[] = Rule::exists($table, 'id');
        }

        return $rules;
    }

    private function level4CategoriesTable(): ?string
    {
        foreach (['level4_categories', 'circle_category_level4'] as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }
}
