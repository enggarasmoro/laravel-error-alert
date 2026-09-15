<?php

return [
    'enabled' => (bool) env('ERROR_ALERT_ENABLED', false),
    'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('ERROR_ALERT_ENVIRONMENTS', 'production'))))),
    'service' => env('ERROR_ALERT_SERVICE', env('APP_NAME', 'laravel-app')),
    'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('ERROR_ALERT_RECIPIENTS', ''))))),
    'mailer' => env('ERROR_ALERT_MAILER', null),
    'queue' => env('ERROR_ALERT_QUEUE', 'error-alerts'),
    'connection' => env('ERROR_ALERT_QUEUE_CONNECTION', null),
    'cache_store' => env('ERROR_ALERT_CACHE_STORE', null),
    'cooldown' => max(1, (int) env('ERROR_ALERT_COOLDOWN', 900)),
    'max_per_hour' => max(1, (int) env('ERROR_ALERT_MAX_PER_HOUR', 20)),
    'max_backlog' => max(1, (int) env('ERROR_ALERT_MAX_BACKLOG', 100)),
];
