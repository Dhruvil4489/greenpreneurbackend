<?php

namespace App\Mail;

use App\Models\FeedbackForm;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FeedbackSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FeedbackForm $feedbackForm)
    {
    }

    public function build(): self
    {
        return $this->subject('Thank you for contacting Peers Global Unity')
            ->view('emails.feedback_submitted');
    }
}
