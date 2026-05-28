<?php

namespace App\Console\Commands;

use App\Models\EventRegistration;
use App\Services\Zoho\ZohoPaymentWebhookService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestZohoCustomerPaymentWebhook extends Command
{
    protected $signature = 'zoho:webhook:test-customer-payment {registration_id}';
    protected $description = 'Simulate a Zoho Billing Customer Payment workflow webhook for an event registration.';

    public function handle(ZohoPaymentWebhookService $service): int
    {
        $registration = EventRegistration::query()->findOrFail((string) $this->argument('registration_id'));
        $payload = [
            'payment' => [
                'payment_id' => $registration->zoho_payment_id,
                'payment_link_id' => $registration->zoho_payment_link_id,
                'reference_number' => $registration->zoho_payment_id ?: $registration->zoho_payment_link_id,
                'description' => 'Event registration payment via Zoho Payment Link '.$registration->zoho_payment_link_id.' / original payment '.$registration->zoho_payment_id,
                'amount' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
                'customer_id' => $registration->zoho_customer_id,
                'date' => optional($registration->payment_completed_at)->toDateString() ?: now()->toDateString(),
                'status' => 'success',
                'payment_status' => 'paid',
            ],
        ];

        $request = Request::create('/api/v1/webhooks/zoho/payments', 'POST', [], [], [], [], json_encode($payload));
        $request->headers->set('Content-Type', 'application/json');
        $result = $service->handle($request);
        $registration->refresh();
        $this->line('message='.(string) ($result['message'] ?? ''));
        $this->line('webhook_event_id='.(string) ($result['webhook_event_id'] ?? ''));
        $this->line('registration_found='.json_encode($result['registration_found'] ?? null));
        $this->info('payment_status='.$registration->payment_status.' invoice_status='.$registration->zoho_invoice_status.' qr='.(string) ($registration->qr_code_url ?: $registration->qr_code_path));

        return self::SUCCESS;
    }
}
