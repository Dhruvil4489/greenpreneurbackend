<?php

namespace App\Http\Requests\Forms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class StoreVisitorRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_type' => ['required', Rule::in(['physical', 'virtual'])],
            'event_name' => ['required', 'string', 'max:190'],
            'event_date' => ['required', 'date'],
            'visitor_full_name' => ['required', 'string', 'max:150'],
            'visitor_mobile' => ['required', 'string', 'max:30'],
            'visitor_email' => ['nullable', 'email', 'max:190'],
            'visitor_city' => ['required', 'string', 'max:120'],
            'visitor_business' => ['required', 'string', 'max:150'],
            'visitor_designation' => ['nullable', 'string', 'max:150'],
            'visitor_business_category_id' => $this->visitorBusinessCategoryIdRules(),
            'visitor_business_category' => ['nullable', 'string', 'max:150'],
            'visitor_business_website' => ['nullable', 'url', 'max:255'],
            'visitor_business_brief' => ['nullable', 'string', 'max:2000'],
            'invited_by_type' => ['nullable', 'string', Rule::in(['peers_global_team', 'circle_member_peer', 'other'])],
            'invited_by_user_id' => ['nullable', 'uuid', 'exists:users,id', 'required_if:invited_by_type,circle_member_peer,other'],
            'how_known' => ['nullable', Rule::in(['friend', 'business_associate', 'client', 'family', 'community_contact', 'peers_global_team', 'circle_member_peer', 'other'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function visitorBusinessCategoryIdRules(): array
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
