<?php

namespace App\Console\Commands;

use App\Models\EventRegistration;
use App\Services\Zoho\ZohoPaymentWebhookService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestZohoPaidWebhook extends Command
{
    protected $signature = 'zoho:webhook:test-paid {registration_id}';
    protected $description = 'Simulate a paid Zoho payment webhook for an event registration (local helper).';

    public function handle(ZohoPaymentWebhookService $service): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is only available in local environment.');
            return self::FAILURE;
        }
        $registration = EventRegistration::query()->findOrFail((string) $this->argument('registration_id'));
        $payload = [
            'event_type' => 'payment_link.paid',
            'event_id' => 'local-test-'.$registration->id.'-'.now()->timestamp,
            'status' => 'paid',
            'payment_link_id' => $registration->zoho_payment_link_id,
            'payment_id' => $registration->zoho_payment_id,
            'registration_id' => $registration->id,
        ];
        $request = Request::create('/api/v1/webhooks/zoho/payments', 'POST', [], [], [], [], json_encode($payload));
        $request->headers->set('Content-Type', 'application/json');
        $service->handle($request);
        $registration->refresh();
        $this->info('payment_status='.$registration->payment_status.' invoice_status='.$registration->zoho_invoice_status.' qr='.(string) ($registration->qr_code_url ?: $registration->qr_code_path));
        return self::SUCCESS;
    }
}
