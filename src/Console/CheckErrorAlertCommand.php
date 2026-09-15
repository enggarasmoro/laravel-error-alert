<?php

namespace Enggarasmoro\LaravelErrorAlert\Console;

use Illuminate\Console\Command;

class CheckErrorAlertCommand extends Command
{
    protected $signature = 'error-alert:check';

    protected $description = 'Validate error alert configuration without sending email.';

    public function handle()
    {
        $config = config('error-alert');
        if (empty($config['recipients'])) {
            $this->error('ERROR_ALERT_RECIPIENTS is empty.');

            return 1;
        }
        $this->info('Error alert configuration is present.');

        return 0;
    }
}
