<?php

namespace Enggarasmoro\LaravelErrorAlert\Events;

class AlertRequested
{
    /** @var array<string, mixed> */
    public $payload;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        $this->payload = array_intersect_key($payload, array_flip([
            'service', 'environment', 'source', 'status', 'error_code', 'type',
            'operation', 'correlation_id', 'occurred_at',
        ]));
    }
}
