<?php

namespace Enggarasmoro\LaravelErrorAlert;

use Enggarasmoro\LaravelErrorAlert\Jobs\SendErrorAlert;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ErrorAlertManager
{
    protected $app;

    protected $reported = [];

    public function __construct($app)
    {
        $this->app = $app;
    }

    public function report($exception, array $context = [])
    {
        try {
            if (! $this->enabled() || ! $this->shouldReport($exception)) {
                return false;
            }

            $payload = $this->payload($exception, $context);
            if ($this->app->runningInConsole()) {
                return $this->enqueue($payload);
            }
            $requestKey = $this->requestKey();
            if ($requestKey !== null) {
                $this->reported[$requestKey] = $payload;

                return true;
            }

            return $this->enqueue($payload);
        } catch (Throwable $alertException) {
            $this->log('error_alert_report_failed', ['type' => get_class($alertException)]);

            return false;
        }
    }

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

                return $this->enqueue($payload);
            }

            return $this->enqueue($this->payload(null, [
                'request' => $request,
                'status' => (int) $response->getStatusCode(),
                'source' => 'http',
            ]));
        } catch (Throwable $alertException) {
            $this->log('error_alert_response_failed', ['type' => get_class($alertException)]);

            return false;
        }
    }

    public function enabled()
    {
        $config = $this->config();

        return (bool) $config['enabled'] && in_array($this->app->environment(), $config['environments'], true);
    }

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

    protected function enqueue(array $payload)
    {
        try {
            if (! $this->reserve($payload)) {
                return false;
            }

            $config = $this->config();
            $job = new SendErrorAlert($payload, $config['connection'], $config['queue']);
            $this->app->make('Illuminate\\Contracts\\Bus\\Dispatcher')->dispatch($job);

            return true;
        } catch (Throwable $exception) {
            $this->releaseBacklog($payload);
            $this->log('error_alert_enqueue_failed', ['type' => get_class($exception)]);

            return false;
        }
    }

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
        $backlog = $cache->increment($backlogKey);
        if ((int) $backlog > $config['max_backlog']) {
            $cache->decrement($backlogKey);

            return false;
        }

        return true;
    }

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

    protected function payload($exception, array $context = [])
    {
        $config = $this->config();
        $request = isset($context['request']) ? $context['request'] : (function_exists('request') ? request() : null);
        $status = isset($context['status']) ? (int) $context['status'] : 500;
        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
        }

        $errorCode = null;
        if ($exception && isset($exception->errorCode) && is_string($exception->errorCode)) {
            $errorCode = $exception->errorCode;
        } elseif ($exception && method_exists($exception, 'errorCode')) {
            $value = $exception->errorCode();
            $errorCode = is_string($value) ? $value : null;
        }

        $operation = isset($context['operation']) ? $context['operation'] : null;
        if (! $operation && $request && method_exists($request, 'path')) {
            $operation = substr($request->method().' '.$request->path(), 0, 160);
        }
        $type = $exception ? get_class($exception) : 'HttpErrorResponse';
        $fingerprint = implode('|', [$config['service'], $this->app->environment(), $context['source'] ?? 'http', $status, $errorCode ?: 'none', $type, $operation ?: 'none']);

        return [
            'service' => substr((string) $config['service'], 0, 80),
            'environment' => (string) $this->app->environment(),
            'source' => isset($context['source']) ? (string) $context['source'] : 'http',
            'status' => $status,
            'error_code' => $errorCode ? substr($errorCode, 0, 120) : null,
            'type' => substr($type, 0, 180),
            'operation' => $operation ? substr((string) $operation, 0, 160) : null,
            'correlation_id' => $this->correlationId($request),
            'occurred_at' => gmdate('c'),
            'fingerprint' => $fingerprint,
            'recipients' => $config['recipients'],
            'mailer' => $config['mailer'],
            'cache_store' => $config['cache_store'],
            'backlog_key' => 'enggarasmoro:error-alert:backlog:'.sha1($config['service'].'|'.$this->app->environment()),
        ];
    }

    protected function correlationId($request)
    {
        if (! $request || ! method_exists($request, 'header')) {
            return null;
        }
        $value = $request->header('X-Correlation-Id');

        return is_string($value) && preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $value) ? $value : null;
    }

    protected function requestKey($request = null)
    {
        $request = $request ?: (function_exists('request') ? request() : null);

        return $request ? spl_object_hash($request) : null;
    }

    protected function config()
    {
        return array_merge([
            'enabled' => false, 'environments' => ['production'], 'service' => 'laravel-app',
            'recipients' => [], 'mailer' => null, 'queue' => 'error-alerts', 'connection' => null,
            'cache_store' => null, 'cooldown' => 900, 'max_per_hour' => 20, 'max_backlog' => 100,
        ], (array) $this->app['config']->get('error-alert', []));
    }

    protected function exceptionStatus($exception)
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }
        if (isset($exception->status) && is_numeric($exception->status)) {
            return (int) $exception->status;
        }
        if (method_exists($exception, 'getStatusCode')) {
            return (int) $exception->getStatusCode();
        }

        return null;
    }

    protected function log($event, array $context)
    {
        try {
            $this->app['log']->error($event, $context);
        } catch (Throwable $ignored) {
            // Alerting must never replace the application failure path.
        }
    }
}
