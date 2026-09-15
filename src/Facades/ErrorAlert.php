<?php

namespace Enggarasmoro\LaravelErrorAlert\Facades;

use Illuminate\Support\Facades\Facade;

class ErrorAlert extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'enggarasmoro.error-alert';
    }
}
