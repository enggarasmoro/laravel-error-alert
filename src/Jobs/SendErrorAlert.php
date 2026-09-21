<?php

namespace Enggarasmoro\LaravelErrorAlert\Jobs;

use Enggarasmoro\LaravelErrorAlert\Mail\ErrorAlertMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendErrorAlert implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $timeout = 30;

    /** @var array<string, mixed>|string */
    public $payload;

    /** @var bool */
    public $encrypted = false;

    /** @var string|null */
    public $backlogKey;

    /** @var string|null */
    public $backlogGenerationKey;

    /** @var string|null */
    public $backlogCounterKey;

    /** @var int|null */
    public $backlogGenerationExpiresAt;

    /** @var string|null */
    public $cacheStore;

    /** @var bool */
    public $ownsBacklogReservation = false;

    /** @var bool */
    public $backlogReleased = false;

    /**
     * @param  array<string, mixed>  $payload
     * @param  string|null  $connection
     * @param  string|null  $queue
     * @param  bool  $encrypt
     * @param  bool  $ownsBacklogReservation
     */
    public function __construct(array $payload, $connection = null, $queue = null, $encrypt = false, $ownsBacklogReservation = false)
    {
        $this->encrypted = (bool) $encrypt;
        $this->backlogKey = isset($payload['backlog_key']) ? (string) $payload['backlog_key'] : null;
        $this->backlogGenerationKey = isset($payload['backlog_generation_key']) ? (string) $payload['backlog_generation_key'] : null;
        $this->backlogCounterKey = isset($payload['backlog_counter_key']) ? (string) $payload['backlog_counter_key'] : null;
        $this->backlogGenerationExpiresAt = isset($payload['backlog_generation_expires_at']) ? (int) $payload['backlog_generation_expires_at'] : null;
        $this->cacheStore = isset($payload['cache_store']) ? (string) $payload['cache_store'] : null;
        $this->ownsBacklogReservation = (bool) $ownsBacklogReservation;
        $this->payload = $this->encrypted ? Crypt::encrypt($payload) : $payload;
        if ($connection) {
            $this->onConnection($connection);
        }
        if ($queue) {
            $this->onQueue($queue);
        }
    }

    /** @return array<int, int> */
    public function backoff()
    {
        return [60, 300];
    }

    /** @return void */
    public function handle()
    {
        $payload = $this->resolvedPayload();
        $recipients = isset($payload['recipients']) && is_array($payload['recipients']) ? $payload['recipients'] : [];
        if ($recipients === []) {
            $this->releaseBacklog();

            return;
        }

        $completed = false;
        try {
            foreach ($recipients as $recipient) {
                $mail = new ErrorAlertMail($payload);
                if (! empty($payload['mailer'])) {
                    $this->namedMailer($payload['mailer'])->to($recipient)->send($mail);
                } else {
                    Mail::to($recipient)->send($mail);
                }
            }
            $completed = true;
        } finally {
            if ($completed) {
                $this->releaseBacklog();
            }
        }
    }

    /**
     * @param  mixed  $exception
     * @return void
     */
    public function failed($exception)
    {
        $this->releaseBacklog();
    }

    /** @return void */
    protected function releaseBacklog()
    {
        if (! $this->ownsBacklogReservation || $this->backlogReleased) {
            return;
        }

        try {
            $payload = is_array($this->payload) ? $this->payload : [];
            $cacheStore = $this->cacheStore ?: (isset($payload['cache_store']) ? $payload['cache_store'] : null);
            $generationKey = $this->backlogGenerationKey ?: (isset($payload['backlog_generation_key']) ? $payload['backlog_generation_key'] : null);
            $counterKey = $this->backlogCounterKey ?: (isset($payload['backlog_counter_key']) ? $payload['backlog_counter_key'] : null);
            $store = ! empty($cacheStore) ? Cache::store($cacheStore) : Cache::store();
            if ($generationKey === null || $generationKey === '' || $counterKey === null || $counterKey === '') {
                $this->backlogReleased = true;

                return;
            }

            $payloadExpiresAt = isset($payload['backlog_generation_expires_at'])
                ? (int) $payload['backlog_generation_expires_at']
                : (int) $this->backlogGenerationExpiresAt;
            $this->withCacheLock($store, $counterKey.':release-lock', function () use ($store, $generationKey, $counterKey, $payloadExpiresAt) {
                $released = $store->get($generationKey, null);
                if ($released === null) {
                    return;
                }
                if ($payloadExpiresAt > 0 && $payloadExpiresAt <= time()) {
                    $store->forget($generationKey);

                    return;
                }

                $store->forget($generationKey);
                try {
                    $store->decrement($counterKey);
                } catch (\Throwable $exception) {
                    try {
                        $store->add($generationKey, $released, $this->backlogReleaseTtl($payloadExpiresAt));
                    } catch (\Throwable $restoreException) {
                        Log::error('error_alert_backlog_release_restore_failed', ['type' => get_class($restoreException)]);
                    }

                    throw $exception;
                }
            });
            $this->backlogReleased = true;
        } catch (\Throwable $ignored) {
            try {
                Log::error('error_alert_backlog_release_failed', ['type' => get_class($ignored)]);
            } catch (\Throwable $logException) {
                // Alert bookkeeping must never create a second alert.
            }
        }
    }

    /**
     * @param  mixed  $store
     * @param  string  $key
     * @param  callable  $callback
     * @return mixed
     */
    protected function withCacheLock($store, $key, callable $callback)
    {
        $underlying = is_object($store) && method_exists($store, 'getStore')
            ? $store->getStore()
            : $store;
        $supportsLock = $underlying instanceof LockProvider
            || (is_object($underlying)
                && method_exists($underlying, 'lock')
                && method_exists($underlying, 'restoreLock'));
        if (! $supportsLock) {
            return $callback();
        }

        $lock = $underlying->lock($key, 10);
        if (is_object($lock) && method_exists($lock, 'block')) {
            return $lock->block(1, $callback);
        }
        if (! is_object($lock) || ! method_exists($lock, 'get') || ! $lock->get()) {
            throw new \RuntimeException('Unable to acquire the error alert backlog release lock.');
        }

        try {
            return $callback();
        } finally {
            if (method_exists($lock, 'release')) {
                $lock->release();
            }
        }
    }

    /**
     * @param  int  $expiresAt
     * @return int
     */
    protected function backlogReleaseTtl($expiresAt)
    {
        if ($expiresAt > 0) {
            return max(1, $expiresAt - time());
        }

        return 86400;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolvedPayload()
    {
        if (! $this->encrypted) {
            return is_array($this->payload) ? $this->payload : [];
        }

        try {
            $ciphertext = is_string($this->payload) ? $this->payload : '';
            $payload = Crypt::decrypt($ciphertext);

            return is_array($payload) ? $payload : [];
        } catch (\Throwable $exception) {
            throw $exception;
        }
    }

    /**
     * @param  string  $name
     * @return mixed
     */
    protected function namedMailer($name)
    {
        $manager = Mail::getFacadeRoot();
        if (is_object($manager) && method_exists($manager, 'mailer')) {
            return $manager->mailer($name);
        }

        throw new \RuntimeException(
            'Named mailers are not supported by this Laravel mail configuration; unset ERROR_ALERT_MAILER and use the default mailer.'
        );
    }
}
