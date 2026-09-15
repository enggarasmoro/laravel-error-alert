<?php

namespace Enggarasmoro\LaravelErrorAlert\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

class ErrorAlertMail extends Mailable
{
    use Queueable;

    public $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function build()
    {
        $mail = $this->subject(sprintf('[%s] %s error alert', $this->payload['service'], strtoupper($this->payload['source'])));
        if (! empty($this->payload['mailer'])) {
            $mail->mailer($this->payload['mailer']);
        }

        return $mail->view('enggarasmoro-error-alert::email');
    }
}
