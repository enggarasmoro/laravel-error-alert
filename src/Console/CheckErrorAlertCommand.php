<?php

namespace Enggarasmoro\LaravelErrorAlert\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CheckErrorAlertCommand extends Command
{
    protected $signature = 'error-alert:check';

    protected $description = 'Validate error alert configuration without sending email.';

    /**
     * @return int
     */
    public function handle()
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->configuration('error-alert', []);
        $errors = [];
        $warnings = [];
        if (empty($config['recipients'])) {
            $errors[] = 'ERROR_ALERT_RECIPIENTS is empty.';
        } else {
            foreach ((array) $config['recipients'] as $recipient) {
                if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid alert recipient: {$recipient}";
                }
            }
        }

        $delivery = strtolower(trim((string) ($config['delivery'] ?? 'queue')));
        if (! in_array($delivery, ['queue', 'sync'], true)) {
            $errors[] = 'ERROR_ALERT_DELIVERY must be either queue or sync.';
        } elseif ($delivery === 'queue') {
            $connection = isset($config['connection']) ? trim((string) $config['connection']) : '';
            if ($connection === '') {
                $connection = trim((string) $this->configuration('queue.default', ''));
            }
            if ($connection === '') {
                $errors[] = 'ERROR_ALERT_QUEUE_CONNECTION is empty and queue.default is not configured.';
            } elseif (strtolower($connection) === 'sync' && ! $this->syncQueueAllowed($config)) {
                $errors[] = 'Synchronous queue driver is disabled. Configure ERROR_ALERT_DELIVERY=sync for direct mail or use a worker-backed queue connection.';
            } else {
                $connections = (array) $this->configuration('queue.connections', []);
                if ($connections !== [] && ! array_key_exists($connection, $connections)) {
                    $errors[] = "Configured queue connection does not exist: {$connection}";
                } elseif (isset($connections[$connection]) && is_array($connections[$connection])) {
                    $driver = strtolower((string) ($connections[$connection]['driver'] ?? ''));
                    if ($driver === '') {
                        $errors[] = "Configured queue connection has no driver: {$connection}";
                    } elseif ($driver === 'sync' && ! $this->syncQueueAllowed($config)) {
                        $errors[] = 'Synchronous queue driver is disabled. Configure ERROR_ALERT_DELIVERY=sync for direct mail or use a worker-backed queue connection.';
                    }
                }
            }

            if (empty($config['queue'])) {
                $errors[] = 'ERROR_ALERT_QUEUE is empty.';
            }

            if ($this->applicationEnvironment('production') && empty($config['encrypt_payload'])) {
                $warnings[] = 'WARNING: queued alert payload encryption is disabled in production; recipients and diagnostic details may be readable in queue storage.';
            }
        }

        $this->validateCacheStore($config, $errors);

        if (! empty($config['mailer'])) {
            $mailers = (array) $this->configuration('mail.mailers', []);
            if ($mailers !== [] && ! array_key_exists($config['mailer'], $mailers)) {
                $errors[] = "Configured mailer does not exist: {$config['mailer']}";
            } elseif ($mailers === []) {
                $errors[] = 'ERROR_ALERT_MAILER requires named mailer support; unset it and use the default mailer on this Laravel version.';
            }
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return 1;
        }
        $this->info('Error alert configuration is present.');

        return 0;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return bool
     */
    protected function syncQueueAllowed(array $config)
    {
        return (bool) ($config['allow_sync'] ?? false)
            && in_array($this->applicationEnvironment(), ['local', 'testing'], true);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $errors
     * @return void
     */
    protected function validateCacheStore(array $config, array &$errors)
    {
        $configuredStore = isset($config['cache_store']) ? trim((string) $config['cache_store']) : '';
        $defaultStore = trim((string) $this->configuration('cache.default', ''));
        $storeName = $configuredStore !== '' ? $configuredStore : $defaultStore;
        if ($storeName === '') {
            $errors[] = 'ERROR_ALERT_CACHE_STORE is empty and cache.default is not configured.';

            return;
        }

        $stores = (array) $this->configuration('cache.stores', []);
        if ($stores !== [] && ! array_key_exists($storeName, $stores)) {
            $errors[] = "Configured cache store does not exist: {$storeName}";

            return;
        }

        $probeKey = null;
        $probeCreated = false;
        try {
            $probeKey = '__enggarasmoro_error_alert_check:'.bin2hex(random_bytes(16));
            $store = $configuredStore !== '' ? Cache::store($configuredStore) : Cache::store();
            $probeCreated = $store->add($probeKey, 1, 60);
            if (! $probeCreated
                || (int) $store->increment($probeKey) !== 2) {
                throw new \RuntimeException('Cache store does not support alert reservation writes.');
            }

            $store->put($probeKey, 4, 60);
            if ((int) $store->decrement($probeKey) !== 3
                || (int) $store->get($probeKey, 0) !== 3) {
                throw new \RuntimeException('Cache store does not support alert reservation writes.');
            }
        } catch (Throwable $exception) {
            $errors[] = "Configured cache store is unavailable: {$storeName}";
        } finally {
            if (isset($store) && $probeCreated && $probeKey !== null) {
                try {
                    $store->forget($probeKey);
                } catch (Throwable $ignored) {
                    // The unique probe key expires after one minute if cleanup is unavailable.
                }
            }
        }
    }

    /**
     * Resolve configuration through the command's bound Laravel application.
     * This keeps package-only command tests independent of the global helper
     * while retaining the helper fallback for legacy Laravel applications.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    protected function configuration(string $key, $default = null)
    {
        try {
            $application = $this->getLaravel();
            $config = $application->make('config');
            if (is_object($config) && method_exists($config, 'get')) {
                return $config->get($key, $default);
            }
        } catch (Throwable $ignored) {
            // Fall through to the legacy helper when a container is unavailable.
        }

        if (function_exists('config')) {
            return config($key, $default);
        }

        return $default;
    }

    /**
     * @param  string|null  $expected
     * @return string|bool
     */
    protected function applicationEnvironment($expected = null)
    {
        try {
            $application = $this->getLaravel();
            $environment = $application->environment();

            return $expected === null ? $environment : $environment === $expected;
        } catch (Throwable $ignored) {
            // Fall through to the legacy helper when a container is unavailable.
        }

        if (function_exists('app')) {
            try {
                $environment = app()->environment();

                return $expected === null ? $environment : $environment === $expected;
            } catch (Throwable $ignored) {
                // Treat an unavailable application as not matching the environment.
            }
        }

        return $expected === null ? '' : false;
    }
}
