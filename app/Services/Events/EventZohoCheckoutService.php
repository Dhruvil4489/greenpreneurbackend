<?php

namespace App\Services\Events;

use App\Models\EventRegistration;

class EventZohoCheckoutService
{
    public function createForRegistration(EventRegistration $registration): array
    {
        throw new \LogicException('Event Zoho checkout is disabled. Event payments use Razorpay checkout.');
    }
}
