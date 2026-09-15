<?php

namespace Enggarasmoro\LaravelErrorAlert\Events;

class AlertRequested
{
    public $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }
}
