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
            ],
        ];
    }
}
