<?php

namespace Enggarasmoro\LaravelErrorAlert\Console;

use Illuminate\Console\Command;

class TestErrorAlertCommand extends Command
{
    protected $signature = 'error-alert:test';

    protected $description = 'Queue a sanitized test error alert.';

    public function handle()
    {
        $manager = app('enggarasmoro.error-alert');
        if (! $manager->report(new \RuntimeException('manual error-alert test'), ['source' => 'manual'])) {
            $this->warn('No alert queued. Enable the feature, configure recipients, and use an allowed environment.');

            return 1;
        }
        $this->info('Test alert queued.');

        return 0;
    }
}
