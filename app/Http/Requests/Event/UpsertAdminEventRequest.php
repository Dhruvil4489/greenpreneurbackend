<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class UpsertAdminEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'circle_id' => ['nullable', 'uuid', 'exists:circles,id'],
            'district_id' => ['nullable', 'uuid'],
            'created_by_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'organizer_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'title' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_at' => [$this->isMethod('post') ? 'required' : 'sometimes', 'date'],
            'end_at' => ['nullable', 'date', 'after:start_at'],
            'is_virtual' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'string', 'in:offline,online,hybrid'],
            'location_text' => ['nullable', 'string'],
            'online_meeting_url' => ['nullable', 'string', 'max:2000'],
            'zoho_form_url' => ['nullable', 'string', 'max:2000'],
            'visitor_registration_enabled' => ['sometimes', 'boolean'],
            'member_registration_enabled' => ['sometimes', 'boolean'],
            'agenda' => ['nullable', 'string'],
            'speakers' => ['nullable', 'array'],
            'banner_url' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'string', 'in:public,circle,connections,private'],
            'is_paid' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'event_type' => ['sometimes', 'string', 'in:circle_meeting,global_event,public_event,training'],
            'event_category' => ['nullable', 'string', 'max:100'],
            'registration_limit' => ['nullable', 'integer', 'min:1'],
            'ticket_price' => ['nullable', 'numeric', 'min:0'],
            'revenue_target' => ['nullable', 'numeric', 'min:0'],
            'total_revenue' => ['nullable', 'numeric', 'min:0'],
            'total_expenses' => ['nullable', 'numeric', 'min:0'],
            'net_pnl' => ['nullable', 'numeric'],
            'qr_checkin_enabled' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'recurrence_type' => ['sometimes', 'string', 'in:none,weekly,monthly,yearly'],
            'recurrence_interval' => ['nullable', 'integer', 'min:1', 'max:24'],
            'recurrence_day_of_week' => ['nullable', 'integer', 'min:1', 'max:7'],
            'recurrence_week_of_month' => ['nullable', 'integer', 'min:1', 'max:5'],
            'recurrence_day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'recurrence_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'recurrence_ends_at' => ['nullable', 'date', 'after:start_at'],
        ];
    }
}
