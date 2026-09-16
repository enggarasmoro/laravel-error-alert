<?php

namespace Enggarasmoro\LaravelErrorAlert;

use Enggarasmoro\LaravelErrorAlert\Events\AlertRequested;
use Enggarasmoro\LaravelErrorAlert\Jobs\SendErrorAlert;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ErrorAlertManager
{
    /** @var mixed */
    protected $app;

    /** @var array<string, array<string, mixed>> */
    protected $reported = [];

    /** @var bool */
    protected $invalidDeliveryLogged = false;

    /**
     * @param  mixed  $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * @param  mixed  $exception
     * @param  array<string, mixed>  $context
     * @return bool
     */
    public function report($exception, array $context = [])
    {
        try {
            if (! $this->enabled() || ! $this->shouldReport($exception)) {
                return false;
            }

            $payload = $this->payload($exception, $context);
            if ($this->app->runningInConsole()) {
                return $this->deliver($payload);
            }
            $requestKey = $this->requestKey();
            if ($requestKey !== null) {
                $this->reported[$requestKey] = $payload;

                return true;
            }

            return $this->deliver($payload);
        } catch (Throwable $alertException) {
            $this->log('error_alert_report_failed', ['type' => get_class($alertException)]);

            return false;
        }
    }

    /**
     * @param  mixed  $request
     * @param  mixed  $response
     * @return bool
     */
    public function handleResponse($request, $response)
    {
        try {
            if (! $this->enabled()) {
                return false;
            }

            $key = $this->requestKey($request);
            if (! $response || (int) $response->getStatusCode() < 500) {
                if ($key !== null) {
                    unset($this->reported[$key]);
                }

                return false;
            }

            if ($key !== null && isset($this->reported[$key])) {
                $payload = $this->reported[$key];
                unset($this->reported[$key]);

                return $this->deliver($payload);
            }

            return $this->deliver($this->payload(null, [
                'request' => $request,
                'status' => (int) $response->getStatusCode(),
                'source' => 'http',
            ]));
        } catch (Throwable $alertException) {
            $this->log('error_alert_response_failed', ['type' => get_class($alertException)]);

            return false;
        }
    }

    /** @return bool */
    public function enabled()
    {
        $config = $this->config();

        return (bool) $config['enabled'] && in_array($this->app->environment(), $config['environments'], true);
    }

    /**
     * @param  mixed  $exception
     * @return bool
     */
    public function shouldReport($exception)
    {
        if (! $exception instanceof Throwable) {
            return false;
        }

        $status = $this->exceptionStatus($exception);
        if ($status !== null && $status < 500) {
            return false;
        }

        return true;
    }

    /**
     * Deliver an alert using the configured queue or direct mail mode.
     *
     * @param  array<string, mixed>  $payload
     * @return bool
     */
    protected function deliver(array $payload)
    {
        $mode = $this->deliveryMode();
        if ($mode !== 'sync' && $mode !== 'queue') {
            if (! $this->invalidDeliveryLogged) {
                $this->log('error_alert_invalid_delivery_mode', []);
                $this->invalidDeliveryLogged = true;
            }

            return false;
        }

        $this->dispatchRequested($payload);

        if ($mode === 'sync') {
            return $this->sendSynchronously($payload);
        }

        return $this->enqueue($payload);
    }

    /**
     * Send an alert immediately without putting a job on a queue.
     *
     * @param  array<string, mixed>  $payload
     * @return bool
     */
    protected function sendSynchronously(array $payload)
    {
        $reserved = false;
        try {
            if (! $this->reserve($payload)) {
                return false;
            }
            $reserved = true;

            (new SendErrorAlert($payload, null, null, false, true))->handle();
            $reserved = false;

            return true;
        } catch (Throwable $exception) {
            if ($reserved) {
                $this->releaseBacklog($payload);
            }
            $this->log('error_alert_send_failed', ['type' => get_class($exception)]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return bool
     */
    protected function enqueue(array $payload)
    {
        $reserved = false;
        try {
            if (! $this->queueAllowed()) {
                $this->log('error_alert_queue_not_configured', []);

                return false;
            }

            if (! $this->reserve($payload)) {
                return false;
            }
            $reserved = true;

            $config = $this->config();
            $job = new SendErrorAlert($payload, $this->queueConnection(), $config['queue'], $config['encrypt_payload'], true);
            $this->app->make('Illuminate\\Contracts\\Bus\\Dispatcher')->dispatch($job);
            $reserved = false;

            return true;
        } catch (Throwable $exception) {
            if ($reserved) {
                $this->releaseBacklog($payload);
            }
            $this->log('error_alert_enqueue_failed', ['type' => get_class($exception)]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return bool
     */
    protected function reserve(array $payload)
    {
        $config = $this->config();
        $cache = $config['cache_store'] ? Cache::store($config['cache_store']) : Cache::store();
        $fingerprintKey = 'enggarasmoro:error-alert:fingerprint:'.sha1($payload['fingerprint']);
        if (! $cache->add($fingerprintKey, 1, $config['cooldown'])) {
            return false;
        }

        $rateKey = 'enggarasmoro:error-alert:rate:'.sha1($config['service'].'|'.$this->app->environment());
        $count = $cache->increment($rateKey);
        if ((int) $count === 1) {
            $cache->put($rateKey, 1, 3600);
        }
        if ((int) $count > $config['max_per_hour']) {
            return false;
        }

        $backlogKey = $payload['backlog_key'];
        $cache->add($backlogKey, 0, $config['backlog_ttl']);
        $backlog = $cache->increment($backlogKey);
        if ((int) $backlog > $config['max_backlog']) {
            $cache->decrement($backlogKey);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return void
     */
    public function releaseBacklog(array $payload)
    {
        try {
            $config = $this->config();
            $cache = $config['cache_store'] ? Cache::store($config['cache_store']) : Cache::store();
            $cache->decrement($payload['backlog_key']);
        } catch (Throwable $ignored) {
            // A conservative counter is safer than blocking the application path.
        }
    }

    /**
     * @param  mixed  $exception
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function payload($exception, array $context = [])
    {
        $config = $this->config();
        $request = isset($context['request']) ? $context['request'] : $this->currentRequest();
        $status = isset($context['status']) ? (int) $context['status'] : 500;
        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
        }

        $errorCode = null;
        if ($exception instanceof Throwable && isset($exception->errorCode) && is_string($exception->errorCode)) {
            $errorCode = $exception->errorCode;
        } elseif (is_object($exception) && method_exists($exception, 'errorCode')) {
            $value = $exception->errorCode();
            $errorCode = is_string($value) ? $value : null;
        }

        $operation = isset($context['operation']) ? $context['operation'] : null;
        if (! $operation && is_object($request) && method_exists($request, 'path') && method_exists($request, 'method')) {
            $operation = substr(
                (string) call_user_func([$request, 'method']).' '.(string) call_user_func([$request, 'path']),
                0,
                160
            );
        }
        $type = $exception instanceof Throwable ? get_class($exception) : 'HttpErrorResponse';
        $anonymousClassDelimiter = strpos($type, "\0");
        if ($anonymousClassDelimiter !== false) {
            $type = substr($type, 0, $anonymousClassDelimiter);
        }

        $service = $this->normalizeSingleLine($config['service'], 80);
        $environment = $this->normalizeText($this->app->environment());
        $source = $this->normalizeSingleLine(isset($context['source']) ? $context['source'] : 'http', 80);
        $errorCode = $errorCode ? $this->normalizeText($errorCode, 120) : null;
        $type = $this->normalizeText($type, 180);
        $operation = $operation ? $this->normalizeText($operation, 160) : null;
        $correlationId = $this->normalizeText($this->correlationId($request), 128);
        $recipients = $this->normalizeRecipients($config['recipients']);
        $mailer = $this->normalizeText($config['mailer']);
        $cacheStore = $this->normalizeText($config['cache_store']);
        $fingerprint = implode('|', [$service, $environment, $source, $status, $errorCode ?: 'none', $type, $operation ?: 'none']);

        return [
            'service' => $service,
            'environment' => $environment,
            'source' => $source,
            'status' => $status,
            'error_code' => $errorCode,
            'type' => $type,
            'detail' => $this->sanitizedDetail($exception),
            'operation' => $operation,
            'correlation_id' => $correlationId,
            'occurred_at' => $this->normalizeText(gmdate('c')),
            'fingerprint' => $fingerprint,
            'recipients' => $recipients,
            'mailer' => $mailer,
            'cache_store' => $cacheStore,
            'backlog_key' => 'enggarasmoro:error-alert:backlog:'.sha1($service.'|'.$environment),
        ];
    }

    /**
     * @param  mixed  $exception
     * @return string|null
     */
    protected function sanitizedDetail($exception)
    {
        if (! $exception instanceof Throwable) {
            return null;
        }

        $maxLength = (int) $this->config()['detail_max_length'];
        if ($maxLength < 1) {
            return null;
        }

        $detail = trim((string) $this->normalizeText($exception->getMessage()));
        if ($detail === '') {
            return null;
        }

        $detail = preg_replace_callback(
            '/(^|[\r\n])\s*(Cookie|Set-Cookie)\s*:\s*[^\r\n]*/im',
            static function (array $matches) {
                return $matches[1].$matches[2].': [REDACTED]';
            },
            (string) $detail
        );
        $detail = preg_replace(
            '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/is',
            '[REDACTED]',
            (string) $detail
        );
        $detail = preg_replace_callback(
            '/"?(password|passwd|secret|token|access[_-]?token|refresh[_-]?token|api[_-]?key|authorization|cookie|session(?:[_-]?id)?|client[_-]?secret|private[_-]?key|aws[_-]?(?:access[_-]?key[_-]?id|secret[_-]?access[_-]?key)|db[_-]?(?:password|user)|database[_-]?(?:password|user))"?\s*([:=])\s*(?:"[^"]*"|\'[^\']*\'|Bearer\s+[^\s,;]+|[^\s,;]+)/i',
            static function (array $matches) {
                return $matches[1].$matches[2].($matches[2] === ':' ? ' ' : '').'[REDACTED]';
            },
            (string) $detail
        );
        $detail = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', (string) $detail);
        $detail = preg_replace('/([?&](?:password|passwd|token|access[_-]?token|refresh[_-]?token|api[_-]?key|secret|cookie|session(?:[_-]?id)?|client[_-]?secret|private[_-]?key)=)[^&\s]+/i', '$1[REDACTED]', (string) $detail);
        $detail = preg_replace('/(\b[a-z][a-z0-9+.-]*:\/\/[^\/\s:@]+:)[^@\s]+@/i', '$1[REDACTED]@', (string) $detail);
        $detail = trim((string) preg_replace('/\s+/', ' ', (string) $this->normalizeText($detail)));

        if (function_exists('mb_substr')) {
            return mb_substr($detail, 0, $maxLength, 'UTF-8');
        }

        return substr($detail, 0, $maxLength);
    }

    /**
     * @param  mixed  $request
     * @return string|null
     */
    protected function correlationId($request)
    {
        if (! is_object($request) || ! method_exists($request, 'header')) {
            return null;
        }
        $value = $request->header('X-Correlation-Id');

        return is_string($value) && preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $value) ? $value : null;
    }

    /**
     * @param  mixed|null  $request
     * @return string|null
     */
    protected function requestKey($request = null)
    {
        $request = $request ?: $this->currentRequest();

        return $request ? spl_object_hash($request) : null;
    }

    /** @return mixed */
    protected function currentRequest()
    {
        if (! function_exists('request')) {
            return null;
        }

        try {
            return request();
        } catch (Throwable $ignored) {
            return null;
        }
    }

    /**
     * @param  mixed  $recipients
     * @return array<int, string>
     */
    protected function normalizeRecipients($recipients)
    {
        $normalized = [];
        foreach ((array) $recipients as $recipient) {
            $recipient = $this->normalizeText($recipient);
            if ($recipient !== null && $recipient !== '') {
                $normalized[] = $recipient;
            }
        }

        return $normalized;
    }

    /**
     * @param  mixed  $value
     * @param  int|null  $maxLength
     * @return string|null
     */
    protected function normalizeText($value, $maxLength = null)
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        if ($maxLength !== null) {
            if (function_exists('mb_substr')) {
                return mb_substr($value, 0, (int) $maxLength, 'UTF-8');
            }

            return substr($value, 0, (int) $maxLength);
        }

        return $value;
    }

    /**
     * @param  mixed  $value
     * @param  int  $maxLength
     * @return string|null
     */
    protected function normalizeSingleLine($value, $maxLength)
    {
        $value = $this->normalizeText($value, $maxLength);
        if ($value === null) {
            return null;
        }

        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
    }

    /** @return bool */
    protected function queueAllowed()
    {
        $connection = $this->queueConnection();
        if ($connection === '') {
            return false;
        }

        $isSync = strtolower($connection) === 'sync';
        try {
            $connections = (array) $this->app['config']->get('queue.connections', []);
            if ($connections !== [] && ! array_key_exists($connection, $connections)) {
                return false;
            }

            $connectionConfig = isset($connections[$connection]) && is_array($connections[$connection])
                ? $connections[$connection]
                : [];
            $driver = isset($connectionConfig['driver']) ? strtolower((string) $connectionConfig['driver']) : '';
            $isSync = $isSync || $driver === 'sync';
        } catch (Throwable $ignored) {
            return false;
        }

        if (! $isSync) {
            return true;
        }

        $config = $this->config();

        return (bool) $config['allow_sync'] && in_array($this->app->environment(), ['local', 'testing'], true);
    }

    /** @return string */
    protected function queueConnection()
    {
        $config = $this->config();
        $connection = $this->normalizeText(isset($config['connection']) ? $config['connection'] : null);
        if ($connection !== null && $connection !== '') {
            return $connection;
        }

        try {
            $connection = $this->app['config']->get('queue.default');
        } catch (Throwable $ignored) {
            $connection = null;
        }

        return $this->normalizeText($connection) ?: '';
    }

    /** @return array<string, mixed> */
    protected function config()
    {
        return array_merge([
            'enabled' => false, 'environments' => ['production'], 'service' => 'laravel-app',
            'recipients' => [], 'mailer' => null, 'delivery' => 'queue', 'queue' => 'error-alerts', 'connection' => null, 'allow_sync' => false,
            'encrypt_payload' => true,
            'cache_store' => null, 'cooldown' => 900, 'max_per_hour' => 20, 'max_backlog' => 100,
            'backlog_ttl' => 86400,
            'detail_max_length' => 500,
        ], (array) $this->app['config']->get('error-alert', []));
    }

    /** @return string|null */
    protected function deliveryMode()
    {
        $delivery = strtolower(trim((string) ($this->config()['delivery'] ?? 'queue')));

        return in_array($delivery, ['sync', 'queue'], true) ? $delivery : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return void
     */
    protected function dispatchRequested(array $payload)
    {
        try {
            if (! $this->app->bound('events')) {
                return;
            }

            $safePayload = [];
            foreach ([
                'service', 'environment', 'source', 'status', 'error_code', 'type',
                'operation', 'correlation_id', 'occurred_at',
            ] as $key) {
                if (array_key_exists($key, $payload)) {
                    $safePayload[$key] = $payload[$key];
                }
            }

            $this->app->make('events')->dispatch(new AlertRequested($safePayload));
        } catch (Throwable $exception) {
            $this->log('error_alert_event_dispatch_failed', ['type' => get_class($exception)]);
        }
    }

    /**
     * @param  mixed  $exception
     * @return int|null
     */
    protected function exceptionStatus($exception)
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }
        if (isset($exception->status) && is_numeric($exception->status)) {
            return (int) $exception->status;
        }
        if (is_object($exception) && method_exists($exception, 'getStatusCode')) {
            return (int) $exception->getStatusCode();
        }

        return null;
    }

    /**
     * @param  string  $event
     * @param  array<string, mixed>  $context
     * @return void
     */
    protected function log($event, array $context)
    {
        try {
            $this->app['log']->error($event, $context);
        } catch (Throwable $ignored) {
            // Alerting must never replace the application failure path.
        }
    }
}
