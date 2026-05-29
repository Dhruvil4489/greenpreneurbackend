<?php

namespace App\Http\Resources\Event;

use App\Services\Events\EventQrService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $qr = app(EventQrService::class);

        return [
            'registration_id' => $this->id,
            'event_id' => $this->event_id,
            'occurrence_id' => $this->occurrence_id,
            'status' => $this->status,
            'checkin_status' => $this->checkin_status,
            'registered_at' => optional($this->registered_at)->toISOString(),
            'checked_in_at' => optional($this->checked_in_at)->toISOString(),
            'source' => $this->source,
            'registration_type' => $this->registration_type ?? ($this->user_id ? 'member' : 'visitor'),
            'payment_required' => (bool) ($this->payment_required ?? false),
            'payment_status' => $this->payment_status ?? null,
            'amount' => $this->amount !== null ? (string) $this->amount : null,
            'currency' => $this->currency ?? null,
            'payment_gateway' => ($this->payment_required ?? false) ? 'razorpay' : null,
            'razorpay_order_id' => $this->razorpay_order_id ?? null,
            'razorpay_payment_id' => $this->razorpay_payment_id ?? null,
            'checkout_url' => null,
            'zoho_invoice_id' => $this->zoho_invoice_id ?? null,
            'zoho_invoice_number' => $this->zoho_invoice_number ?? null,
            'invoice_url' => $this->zoho_invoice_url ?? null,
            'invoice_pdf_url' => $this->zoho_invoice_pdf_url ?? null,
            'payment_completed_at' => optional($this->payment_completed_at)->toISOString(),
            'visitor_designation' => $this->visitor_designation ?? data_get($this->metadata, 'visitor_designation'),
            'visitor_business_category_id' => $this->visitor_business_category_id ?? data_get($this->metadata, 'visitor_business_category_id'),
            'visitor_business_category' => $this->visitor_business_category ?? data_get($this->metadata, 'visitor_business_category'),
            'visitor_business_category_main_id' => $this->visitor_business_category_main_id ?? data_get($this->metadata, 'visitor_business_category_main_id'),
            'visitor_business_category_sub_id' => $this->visitor_business_category_sub_id ?? data_get($this->metadata, 'visitor_business_category_sub_id') ?? $this->visitor_business_category_id ?? data_get($this->metadata, 'visitor_business_category_id'),
            'business_category_main' => $this->businessCategoryMainPayload(),
            'business_category_sub' => $this->businessCategorySubPayload(),
            'visitor_business_website' => $this->visitor_business_website ?? data_get($this->metadata, 'visitor_business_website'),
            'visitor_business_brief' => $this->visitor_business_brief ?? data_get($this->metadata, 'visitor_business_brief'),
            'invited_by_type' => $this->invited_by_type ?? data_get($this->metadata, 'invited_by_type'),
            'invited_by_user_id' => $this->invited_by_user_id ?? data_get($this->metadata, 'invited_by_user_id'),
            'invited_by_user' => $this->invitedByUser ? [
                'id' => $this->invitedByUser->id,
                'display_name' => $this->invitedByUser->display_name ?: trim(($this->invitedByUser->first_name ?? '').' '.($this->invitedByUser->last_name ?? '')),
                'first_name' => $this->invitedByUser->first_name,
                'last_name' => $this->invitedByUser->last_name,
                'company_name' => $this->invitedByUser->company_name,
                'designation' => $this->invitedByUser->designation,
                'profile_photo_url' => $this->invitedByUser->profile_photo_url ?? null,
            ] : null,
            'qr_code_url' => ($this->payment_required ?? false) && ($this->payment_status ?? null) !== 'paid' ? null : ($this->qr_code_url ?: $qr->url($this->qr_code_path)),
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event->id,
                'title' => $this->event->title,
                'event_type' => $this->event->event_type,
                'mode' => $this->event->mode,
                'location_text' => $this->event->location_text,
            ]),
            'occurrence' => $this->whenLoaded('occurrence', fn () => [
                'id' => $this->occurrence->id,
                'start_at' => optional($this->occurrence->start_at)->toISOString(),
                'end_at' => optional($this->occurrence->end_at)->toISOString(),
            ]),
            'attendee' => $this->user ? [
                'type' => 'member',
                'id' => $this->user->id,
                'name' => $this->user->display_name ?? trim(($this->user->first_name ?? '').' '.($this->user->last_name ?? '')),
                'email' => $this->user->email,
                'phone' => $this->user->phone ?? null,
                'company_name' => $this->user->company_name ?? null,
                'city' => $this->user->city ?? $this->user->business_city ?? null,
            ] : [
                'type' => 'visitor',
                'name' => $this->visitor_name,
                'email' => $this->visitor_email,
                'phone' => $this->visitor_phone,
                'company' => $this->visitor_company,
                'city' => $this->visitor_city,
                'designation' => $this->visitor_designation ?? data_get($this->metadata, 'visitor_designation'),
                'business_category_id' => $this->visitor_business_category_id ?? data_get($this->metadata, 'visitor_business_category_id'),
                'business_category' => $this->visitor_business_category ?? data_get($this->metadata, 'visitor_business_category'),
                'business_category_main' => $this->businessCategoryMainPayload(),
                'business_category_sub' => $this->businessCategorySubPayload(),
                'business_website' => $this->visitor_business_website ?? data_get($this->metadata, 'visitor_business_website'),
                'business_brief' => $this->visitor_business_brief ?? data_get($this->metadata, 'visitor_business_brief'),
            ],
        ];
    }
}
