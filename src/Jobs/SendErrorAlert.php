<?php

namespace Enggarasmoro\LaravelErrorAlert\Jobs;

use Enggarasmoro\LaravelErrorAlert\Mail\ErrorAlertMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendErrorAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 30;

    public $payload;

    public function __construct(array $payload, $connection = null, $queue = null)
    {
        $this->payload = $payload;
        if ($connection) {
            $this->onConnection($connection);
        }
        if ($queue) {
            $this->onQueue($queue);
        }
    }

    public function backoff()
    {
        return [60, 300];
    }

    public function handle()
    {
        $recipients = isset($this->payload['recipients']) && is_array($this->payload['recipients']) ? $this->payload['recipients'] : [];
        if ($recipients === []) {
            $this->releaseBacklog();

            return;
        }

        $completed = false;
        try {
            foreach ($recipients as $recipient) {
                Mail::to($recipient)->send(new ErrorAlertMail($this->payload));
            }
            $completed = true;
        } finally {
            if ($completed) {
                $this->releaseBacklog();
            }
        }
    }

    public function failed($exception)
    {
        $this->releaseBacklog();
    }

    protected function releaseBacklog()
    {
        try {
            $store = ! empty($this->payload['cache_store']) ? Cache::store($this->payload['cache_store']) : Cache::store();
            $store->decrement($this->payload['backlog_key']);
        } catch (\Throwable $ignored) {
            // Alert bookkeeping must never create a second alert.
        }
    }
}
