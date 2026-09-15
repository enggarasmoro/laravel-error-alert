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

    public function boot($events = null)
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'enggarasmoro-error-alert');
        if ($this->app->bound('events')) {
            $events = $events ?: $this->app['events'];
            $manager = $this->app->make('enggarasmoro.error-alert');
            $events->listen('Illuminate\\Foundation\\Http\\Events\\RequestHandled', function ($event) use ($manager) {
                $manager->handleResponse($event->request, $event->response);
            });
            $events->listen('Illuminate\\Queue\\Events\\JobFailed', function ($event) use ($manager) {
                $name = method_exists($event->job, 'resolveName') ? $event->job->resolveName() : (method_exists($event->job, 'getName') ? $event->job->getName() : '');
                if (is_string($name) && strpos($name, 'Enggarasmoro\\LaravelErrorAlert\\Jobs\\SendErrorAlert') !== false) {
                    return;
                }
                $manager->report($event->exception, ['source' => 'queue', 'operation' => get_class($event->job)]);
            });
        }

        try {
            $handler = $this->app->make('Illuminate\\Contracts\\Debug\\ExceptionHandler');
            if (method_exists($handler, 'reportable')) {
                $manager = $this->app->make('enggarasmoro.error-alert');
                $handler->reportable(function (\Throwable $exception) use ($manager) {
                    $manager->report($exception);
                });
            }
        } catch (\Throwable $ignored) {
            // Laravel 6/7 require the documented manual report() hook.
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckErrorAlertCommand::class, TestErrorAlertCommand::class]);
        }
        $this->publishes([__DIR__.'/../config/error-alert.php' => config_path('error-alert.php')], 'error-alert-config');
    }
}
