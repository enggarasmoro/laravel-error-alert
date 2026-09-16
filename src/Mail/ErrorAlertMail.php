<?php

namespace Enggarasmoro\LaravelErrorAlert\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

class ErrorAlertMail extends Mailable
{
    use Queueable;

    /** @var array<string, mixed> */
    public $payload;

    /** @param array<string, mixed> $payload */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /** @return static */
    public function build()
    {
        $service = $this->singleLine(isset($this->payload['service']) ? $this->payload['service'] : 'laravel-app', 80);
        $source = $this->singleLine(isset($this->payload['source']) ? $this->payload['source'] : 'unknown', 80);

        return $this->subject(sprintf('[%s] %s error alert', $service, strtoupper($source)))
            ->view('enggarasmoro-error-alert::email');
    }

    /**
     * @param  mixed  $value
     * @param  int  $maxLength
     * @return string
     */
    protected function singleLine($value, $maxLength)
    {
        $value = (string) $value;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        $value = (string) preg_replace('/[\r\n\t]+/', ' ', $value);
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }

        return substr($value, 0, $maxLength);
    }
}
