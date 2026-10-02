<?php

namespace Enggarasmoro\LaravelErrorAlert;

use Enggarasmoro\LaravelErrorAlert\Console\CheckErrorAlertCommand;
use Enggarasmoro\LaravelErrorAlert\Console\TestErrorAlertCommand;
use Illuminate\Support\ServiceProvider;

class ErrorAlertServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/error-alert.php', 'error-alert');
        $this->app->singleton('enggarasmoro.error-alert', function ($app) {
            return new ErrorAlertManager($app);
        });
    }

    /**
     * @param  mixed|null  $events
     * @return void
     */
    public function boot($events = null)
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'enggarasmoro-error-alert');
        if ($this->app->bound('events')) {
            $events = $events ?: $this->app->make('events');
            $manager = $this->app->make('enggarasmoro.error-alert');
            $events->listen('Illuminate\\Foundation\\Http\\Events\\RequestHandled', function ($event) use ($manager) {
                $manager->handleResponse($event->request, $event->response);
            });
            $events->listen('Illuminate\\Queue\\Events\\JobFailed', function ($event) use ($manager) {
                $job = $event->job;
                if (! is_object($job)) {
                    return;
                }
                $name = method_exists($job, 'resolveName')
                    ? (string) call_user_func([$job, 'resolveName'])
                    : (method_exists($job, 'getName') ? (string) call_user_func([$job, 'getName']) : '');
                if (strpos($name, 'Enggarasmoro\\LaravelErrorAlert\\Jobs\\SendErrorAlert') !== false) {
                    return;
                }
                $manager->report($event->exception, ['source' => 'queue', 'operation' => get_class($job)]);
            });
        }

        try {
            /** @var mixed $handler */
            $handler = $this->app->make('Illuminate\\Contracts\\Debug\\ExceptionHandler');
        } catch (\Throwable $exception) {
            $this->logHandlerRegistrationFailure($exception);
            $handler = null;
        }

        if (is_object($handler) && method_exists($handler, 'reportable')) {
            try {
                $manager = $this->app->make('enggarasmoro.error-alert');
                /** @var callable $reportable */
                $reportable = [$handler, 'reportable'];
                call_user_func($reportable, function (\Throwable $exception) use ($manager) {
                    $manager->report($exception);
                });
            } catch (\Throwable $exception) {
                $this->logHandlerRegistrationFailure($exception);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckErrorAlertCommand::class, TestErrorAlertCommand::class]);
        }
        $this->publishes([__DIR__.'/../config/error-alert.php' => $this->configurationPath('error-alert.php')], 'error-alert-config');
    }

    /**
     * @param  \Throwable  $exception
     * @return void
     */
    protected function logHandlerRegistrationFailure(\Throwable $exception)
    {
        try {
            $this->app->make('log')->warning('error_alert_exception_handler_registration_failed', [
                'type' => get_class($exception),
            ]);
        } catch (\Throwable $ignored) {
            // Error reporting setup must never replace the application error path.
        }
    }

    /**
     * Resolve the consumer's config directory without requiring Laravel Foundation
     * helper functions, which are not dependencies of this package.
     *
     * @param  string  $path
     * @return string
     */
    protected function configurationPath($path = '')
    {
        return $this->app->configPath($path);
    }
}
