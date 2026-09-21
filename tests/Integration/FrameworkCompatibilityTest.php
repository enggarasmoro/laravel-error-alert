<?php

namespace Enggarasmoro\LaravelErrorAlert\Tests\Integration;

use Enggarasmoro\LaravelErrorAlert\ErrorAlertManager;
use Enggarasmoro\LaravelErrorAlert\Jobs\SendErrorAlert;
use Enggarasmoro\LaravelErrorAlert\Mail\ErrorAlertMail;
use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;

class FrameworkCompatibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_real_array_cache_repository_reserves_and_releases_a_generation(): void
    {
        $config = new IntegrationConfig([
            'error-alert' => [
                'enabled' => true,
                'environments' => ['testing'],
                'service' => 'integration-test',
                'backlog_ttl' => 120,
                'max_backlog' => 2,
            ],
            'cache.default' => 'array',
            'cache.stores.array' => ['driver' => 'array'],
        ]);
        $app = new IntegrationApplication;
        $app->instance('config', $config);
        $cache = new CacheManager($app);
        $app->instance('cache', $cache);
        Container::setInstance($app);
        Cache::swap($cache);

        $manager = new ErrorAlertManager($app);
        $payload = [
            'fingerprint' => 'framework-cache',
            'backlog_key' => 'integration-backlog',
        ];
        $reserve = new \ReflectionMethod($manager, 'reserve');
        $reserve->setAccessible(true);

        $this->assertTrue((bool) $reserve->invokeArgs($manager, [&$payload]));
        $this->assertSame(1, $cache->get('integration-backlog'));
        $this->assertSame(1, $cache->get($payload['backlog_generation_key']));

        $manager->releaseBacklog($payload);

        $this->assertSame(0, $cache->get('integration-backlog'));
        $this->assertNull($cache->get($payload['backlog_generation_key']));
    }

    public function test_real_blade_factory_renders_the_mailable_view(): void
    {
        $filesystem = new Filesystem;
        $cachePath = sys_get_temp_dir().'/enggarasmoro-laravel-error-alert-blade';
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }

        $compiler = new BladeCompiler($filesystem, $cachePath);
        $engines = new EngineResolver;
        $engines->register('blade', function () use ($compiler) {
            return new CompilerEngine($compiler);
        });
        $finder = new FileViewFinder($filesystem, [dirname(__DIR__, 2).'/resources/views']);
        $finder->addNamespace('enggarasmoro-error-alert', dirname(__DIR__, 2).'/resources/views');
        $factory = new Factory($engines, $finder, new Dispatcher(new Container));

        $mail = new ErrorAlertMail([
            'service' => 'integration-test',
            'environment' => 'testing',
            'source' => 'http',
            'status' => 500,
            'error_code' => 'FORM_SCHEMA_INVALID',
            'type' => 'RuntimeException',
            'detail' => 'Database query failed; SQL, bindings, and connection details were omitted.',
            'operation' => 'POST /api/v1/inspections',
            'occurred_at' => '2026-09-21T00:00:00Z',
            'correlation_id' => 'integration-123',
        ]);
        $mail->build();
        $html = $factory->make($mail->view, ['payload' => $mail->payload])->render();

        $this->assertStringContainsString('An incident needs', $html);
        $this->assertStringContainsString('FORM_SCHEMA_INVALID', $html);
        $this->assertStringContainsString('integration-123', $html);
    }

    public function test_encrypted_job_round_trips_through_the_real_encrypter(): void
    {
        $encrypter = new Encrypter(str_repeat('a', 32), 'AES-256-CBC');
        Crypt::swap($encrypter);
        $payload = [
            'service' => 'integration-test',
            'detail' => 'sanitized diagnostic',
            'backlog_key' => 'integration-backlog',
            'backlog_generation_key' => 'integration-backlog:generation:abc',
            'backlog_counter_key' => 'integration-backlog',
        ];
        $job = new SendErrorAlert($payload, 'redis', 'error-alerts', true, true);
        $resolved = new \ReflectionMethod($job, 'resolvedPayload');
        $resolved->setAccessible(true);

        $this->assertIsString($job->payload);
        $this->assertSame($payload, $resolved->invoke($job));
        $this->assertSame('integration-backlog:generation:abc', $job->backlogGenerationKey);
        $this->assertSame('integration-backlog', $job->backlogCounterKey);
    }
}

class IntegrationApplication extends Container
{
    public function environment(...$patterns)
    {
        if ($patterns === []) {
            return 'testing';
        }

        return in_array('testing', $patterns, true);
    }

    public function runningInConsole()
    {
        return true;
    }
}

class IntegrationConfig implements \ArrayAccess
{
    /** @var array<string, mixed> */
    private $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->values);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return array_key_exists($offset, $this->values) ? $this->values[$offset] : null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->values[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->values[$offset]);
    }
}
