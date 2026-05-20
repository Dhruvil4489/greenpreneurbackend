<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupportTicketResolvedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket)
    {
    }

    public function build(): self
    {
        return $this->subject('Support Ticket Resolved - ' . $this->ticket->ticket_number)
            ->view('emails.support-ticket-resolved');
    }
}
