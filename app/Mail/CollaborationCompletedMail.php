<?php

namespace App\Mail;

use App\Models\CollaborationPost;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CollaborationCompletedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public CollaborationPost $collaboration, public User $recipient)
    {
    }

    public function build(): self
    {
        return $this->subject('Collaboration completed: ' . $this->collaboration->title)
            ->view('emails.collaborations.completed');
    }
}
