<?php

namespace Enggarasmoro\LaravelErrorAlert\Console;

use Illuminate\Console\Command;

class TestErrorAlertCommand extends Command
{
    protected $signature = 'error-alert:test
                            {--sync : Send this probe directly through the configured mailer instead of the queue}
                            {--to= : Send this probe to one valid email address instead of configured recipients}';

    protected $description = 'Send a controlled exception probe through the error alert delivery pipeline.';

    /**
     * @return int
     */
    public function handle()
    {
        $application = $this->getLaravel();
        $manager = $application->make('enggarasmoro.error-alert');
        $config = $application->make('config');
        $recipientOptionProvided = $this->input->hasParameterOption('--to');
        $recipient = $this->option('to');
        if ($recipientOptionProvided) {
            if (! is_string($recipient)) {
                $this->error('The probe recipient must be one valid email address.');

                return 1;
            }

            $recipient = trim($recipient);
            if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                $this->error('The probe recipient must be one valid email address.');

                return 1;
            }
        }

        $sync = (bool) $this->option('sync');
        $originalRecipients = $config->get('error-alert.recipients');
        $originalDelivery = $config->get('error-alert.delivery');

        try {
            if ($recipientOptionProvided) {
                $config->set('error-alert.recipients', [$recipient]);
            }

            if ($sync) {
                $config->set('error-alert.delivery', 'sync');
            }

            $delivery = strtolower(trim((string) $config->get('error-alert.delivery', 'queue')));
            $probeId = bin2hex(random_bytes(8));
            $exception = new \RuntimeException(
                'Intentional error-alert delivery probe ('.$probeId.'). No application data was changed.'
            );
            $context = [
                'source' => 'manual',
                'operation' => 'error-alert:test/'.$probeId,
            ];

            if (! $manager->report($exception, $context)) {
                $this->warn('No probe was sent or queued. Check alert configuration, environment, recipient, rate limits, and queue settings.');

                return 1;
            }

            $this->line('Probe ID: '.$probeId);
            if ($delivery === 'sync') {
                $this->info('Probe handed to the configured mailer synchronously. Check the recipient inbox.');
            } else {
                $queue = trim((string) $config->get('error-alert.queue', 'error-alerts'));
                $this->info('Probe queued on "'.$queue.'". Confirm a worker consumes it, then check the recipient inbox.');
            }

            return 0;
        } finally {
            if ($recipientOptionProvided) {
                $config->set('error-alert.recipients', $originalRecipients);
            }

            if ($sync) {
                $config->set('error-alert.delivery', $originalDelivery);
            }
        }
    }
}
