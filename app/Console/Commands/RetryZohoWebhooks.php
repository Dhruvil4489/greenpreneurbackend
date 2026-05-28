<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use App\Services\Zoho\ZohoPaymentWebhookService;
use Illuminate\Console\Command;

class RetryZohoWebhooks extends Command
{
    protected $signature = 'zoho:webhooks:retry-failed {--id=} {--registration_id=} {--limit=50}';
    protected $description = 'Retry failed Zoho payment webhook events.';

    public function handle(ZohoPaymentWebhookService $service): int
    {
        $query = WebhookEvent::query()->where('provider', 'zoho')->where('status', 'failed');
        if ($this->option('id')) $query->where('id', $this->option('id'));
        if ($this->option('registration_id')) $query->where('registration_id', $this->option('registration_id'));
        $events = $query->oldest()->limit((int) $this->option('limit'))->get();
        foreach ($events as $event) {
            $this->line('Retrying webhook '.$event->id.' registration='.(string) $event->registration_id);
            $event->forceFill(['status' => 'received', 'error' => null])->save();
            $service->processStored($event);
            $event->refresh();
            $this->line('Result '.$event->id.' status='.$event->status.' error='.(string) $event->error);
        }
        $this->info('Processed: '.$events->count());
        return self::SUCCESS;
    }
}
