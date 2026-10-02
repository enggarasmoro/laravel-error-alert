<?php

$autoload = getenv('ERROR_ALERT_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
if (! is_file($autoload)) {
    throw new RuntimeException('Composer autoload file is missing for laravel-error-alert tests.');
}

require_once $autoload;

if (! function_exists('config_path')) {
    function config_path($path = '')
    {
        return __DIR__.'/config'.($path !== '' ? DIRECTORY_SEPARATOR.$path : '');
    }
}

spl_autoload_register(static function ($class) {
    $prefix = 'Enggarasmoro\\LaravelErrorAlert\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require_once $path;
    }
}, true, true);
