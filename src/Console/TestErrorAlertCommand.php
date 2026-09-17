<?php

namespace Enggarasmoro\LaravelErrorAlert\Console;

use Illuminate\Console\Command;

class TestErrorAlertCommand extends Command
{
    protected $signature = 'error-alert:test';

    protected $description = 'Send a sanitized test error alert using the configured delivery mode.';

    /**
     * @return int
     */
    public function handle()
    {
        $application = $this->getLaravel();
        $manager = $application->make('enggarasmoro.error-alert');
        if (! $manager->report(new \RuntimeException('manual error-alert test'), ['source' => 'manual'])) {
            $this->warn('No alert sent. Enable the feature, configure recipients, and use an allowed environment.');

            return 1;
        }
        $config = $application->make('config');
        $delivery = strtolower(trim((string) $config->get('error-alert.delivery', 'queue')));
        $this->info($delivery === 'sync' ? 'Test alert sent synchronously.' : 'Test alert queued.');

        return 0;
    }
}
