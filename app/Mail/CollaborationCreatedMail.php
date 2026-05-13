<?php

namespace App\Mail;

use App\Models\CollaborationPost;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CollaborationCreatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public CollaborationPost $collaboration, public User $recipient)
    {
    }

    public function build(): self
    {
        return $this->subject('Your collaboration has been posted successfully')
            ->view('emails.collaborations.created');
    }
}
