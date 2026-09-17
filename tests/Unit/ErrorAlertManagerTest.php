<?php

namespace Enggarasmoro\LaravelErrorAlert\Tests\Unit;

use Enggarasmoro\LaravelErrorAlert\ErrorAlertManager;
use Enggarasmoro\LaravelErrorAlert\Console\CheckErrorAlertCommand;
use Enggarasmoro\LaravelErrorAlert\Console\TestErrorAlertCommand;
use Enggarasmoro\LaravelErrorAlert\Events\AlertRequested;
use Enggarasmoro\LaravelErrorAlert\Jobs\SendErrorAlert;
use Enggarasmoro\LaravelErrorAlert\Mail\ErrorAlertMail;
use Enggarasmoro\LaravelErrorAlert\ErrorAlertServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ErrorAlertManagerTest extends TestCase
{
    /** @var CheckErrorAlertCommand|null */
    protected $lastCommand;

    public function test_disabled_alerts_do_not_touch_the_backend(): void
    {
        $app = new FakeApplication(false, ['enabled' => false]);
        $manager = new ErrorAlertManager($app);

        $this->assertFalse($manager->report(new \RuntimeException('ignored')));
    }

    public function test_client_http_exceptions_are_not_server_alerts(): void
    {
        $app = new FakeApplication(false, ['enabled' => true]);
        $manager = new ErrorAlertManager($app);
        $exception = new HttpException(404, 'not found');

        $this->assertFalse($manager->shouldReport($exception));
    }

    public function test_exception_detail_redacts_cookie_private_key_and_common_credentials(): void
    {
        $app = new FakeApplication(false, [
            'enabled' => true,
            'detail_max_length' => 500,
        ]);
        $manager = new ErrorAlertManager($app);
        $exception = new \RuntimeException(
            "Cookie: session=secret-cookie\nclient_secret=client-secret "
            .'private_key=-----BEGIN PRIVATE KEY-----PRIVATE-DATA-----END PRIVATE KEY----- '
            .'AWS_SECRET_ACCESS_KEY=cloud-secret'
        );

        $payload = $this->invokePayload($manager, $exception);

        $this->assertStringNotContainsString('secret-cookie', $payload['detail']);
        $this->assertStringNotContainsString('client-secret', $payload['detail']);
        $this->assertStringNotContainsString('PRIVATE-DATA', $payload['detail']);
        $this->assertStringNotContainsString('cloud-secret', $payload['detail']);
        $this->assertStringContainsString('Cookie: [REDACTED]', $payload['detail']);
        $this->assertStringContainsString('client_secret=[REDACTED]', $payload['detail']);
        $this->assertStringContainsString('private_key=[REDACTED]', $payload['detail']);
        $this->assertStringContainsString('AWS_SECRET_ACCESS_KEY=[REDACTED]', $payload['detail']);
    }

    public function test_outbound_payload_strings_are_utf8_and_free_of_c0_controls(): void
    {
        $app = new FakeApplication(false, [
            'enabled' => true,
            'service' => "inspection-api\0\x01\r\nBcc: attacker@example.test",
            'detail_max_length' => 500,
        ]);
        $manager = new ErrorAlertManager($app);
        $exception = new \RuntimeException("detail\0\x02\xC3\x28\nnext");

        $payload = $this->invokePayload($manager, $exception, [
            'source' => "http\0\x03\r\nBcc: attacker@example.test",
            'operation' => "POST /probe\0\x04\nnext",
        ]);

        foreach ($payload as $value) {
            if (! is_string($value)) {
                continue;
            }

            $this->assertSame(1, preg_match('//u', $value));
            $this->assertSame(0, preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value));
        }

        $this->assertStringContainsString('detail', $payload['detail']);
        $this->assertStringContainsString('next', $payload['detail']);
        $this->assertStringContainsString('POST /probe', $payload['operation']);
        $this->assertSame('http Bcc: attacker@example.test', $payload['source']);
        $this->assertStringNotContainsString("\n", $payload['service']);
    }

    public function test_queue_is_rejected_when_connection_is_missing_or_sync(): void
    {
        $missingConnection = new ErrorAlertManager(new FakeApplication(false, [
            'enabled' => true,
            'recipients' => ['ops@example.test'],
        ]));
        $syncConnection = new ErrorAlertManager(new FakeApplication(false, [
            'enabled' => true,
            'recipients' => ['ops@example.test'],
            'connection' => 'sync',
        ]));

        $this->assertFalse($this->invokeQueueAllowed($missingConnection));
        $this->assertFalse($this->invokeQueueAllowed($syncConnection));

        $syncDriverAlias = new ErrorAlertManager(new FakeApplication(false, [
            'enabled' => true,
            'connection' => 'default',
            'queue.connections' => ['default' => ['driver' => 'sync']],
        ]));

        $this->assertFalse($this->invokeQueueAllowed($syncDriverAlias));
    }

    public function test_sync_queue_can_be_explicitly_enabled_for_local_testing(): void
    {
        $manager = new ErrorAlertManager(new FakeApplication(false, [
            'enabled' => true,
            'connection' => 'sync',
            'allow_sync' => true,
        ]));

        $this->assertTrue($this->invokeQueueAllowed($manager));
    }

    public function test_delivery_mode_defaults_to_queue_and_accepts_direct_sync(): void
    {
        $defaultManager = new ErrorAlertManager(new FakeApplication(false, [
            'enabled' => true,
        ]));
        $syncManager = new ErrorAlertManager(new FakeApplication(false, [
            'enabled' => true,
            'delivery' => 'sync',
        ]));
        $invalidManager = new ErrorAlertManager(new FakeApplication(false, [
            'enabled' => true,
            'delivery' => 'invalid',
        ]));

        $this->assertSame('queue', $this->invokeDeliveryMode($defaultManager));
        $this->assertSame('sync', $this->invokeDeliveryMode($syncManager));
        $this->assertNull($this->invokeDeliveryMode($invalidManager));
    }

    public function test_delivery_routes_to_the_selected_transport(): void
    {
        $syncManager = new RoutingErrorAlertManager(new FakeApplication(false, [
            'enabled' => true,
            'delivery' => 'sync',
        ]));
        $queueManager = new RoutingErrorAlertManager(new FakeApplication(false, [
            'enabled' => true,
            'delivery' => 'queue',
        ]));

        $this->assertTrue($this->invokeDeliver($syncManager));
        $this->assertTrue($this->invokeDeliver($queueManager));
        $this->assertSame(['sync'], $syncManager->calls);
        $this->assertSame(['queue'], $queueManager->calls);
    }

    public function test_queue_payload_can_be_encrypted_and_restored(): void
    {
        Crypt::swap(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
        $payload = [
            'recipients' => ['ops@example.test'],
            'detail' => 'sanitized incident detail',
            'cache_store' => 'redis',
            'backlog_key' => 'error-alert-backlog',
        ];

        $job = new SendErrorAlert($payload, 'redis', 'error-alerts', true);

        $this->assertIsString($job->payload);
        $this->assertStringNotContainsString('ops@example.test', $job->payload);

        $method = new \ReflectionMethod($job, 'resolvedPayload');
        $method->setAccessible(true);

        $this->assertSame($payload, $method->invoke($job));
    }

    public function test_package_manifest_does_not_require_the_unpublished_foundation_component(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

        $this->assertIsArray($manifest);
        $this->assertArrayNotHasKey('illuminate/foundation', $manifest['require']);
        $this->assertArrayHasKey('illuminate/bus', $manifest['require']);
    }

    public function test_backlog_reservation_sets_a_bounded_ttl(): void
    {
        $cache = new FakeCacheStore;
        $this->installCache($cache);
        $manager = new ErrorAlertManager(new FakeApplication(false, [
            'enabled' => true,
            'backlog_ttl' => 43200,
        ]));

        $this->assertTrue($this->invokeReserve($manager, [
            'fingerprint' => 'fingerprint',
            'backlog_key' => 'alert-backlog',
        ]));
        $this->assertSame(1, $cache->values['alert-backlog']);
        $this->assertSame(43200, $cache->ttls['alert-backlog']);
    }

    public function test_failed_reservation_does_not_decrement_an_unowned_backlog_slot(): void
    {
        $cache = new FakeCacheStore;
        $cache->values['alert-backlog'] = 7;
        $cache->throwOnIncrement = true;
        $this->installCache($cache);
        $manager = new ErrorAlertManager(new FakeApplication(false, ['enabled' => true]));

        $this->assertFalse($this->invokeSyncSend($manager, [
            'fingerprint' => 'fingerprint',
            'backlog_key' => 'alert-backlog',
        ]));
        $this->assertSame(7, $cache->values['alert-backlog']);
    }

    public function test_unowned_job_does_not_release_a_backlog_slot(): void
    {
        $cache = new FakeCacheStore;
        $cache->values['alert-backlog'] = 4;
        $this->installCache($cache);
        $job = new SendErrorAlert([
            'recipients' => [],
            'cache_store' => null,
            'backlog_key' => 'alert-backlog',
        ]);

        $job->failed(new \RuntimeException('failed'));

        $this->assertSame(4, $cache->values['alert-backlog']);
    }

    public function test_sync_delivery_uses_the_selected_mailer_and_safe_single_line_subject(): void
    {
        $cache = new FakeCacheStore;
        $mail = new FakeMailManager;
        $events = new FakeEventDispatcher;
        $app = new FakeApplication(true, [
            'enabled' => true,
            'environments' => ['testing'],
            'delivery' => 'sync',
            'service' => "inspection-api\r\nBcc: attacker@example.test",
            'source' => "http\nBcc: attacker@example.test",
            'recipients' => ['ops@example.test'],
            'mailer' => 'transactional',
        ]);
        $app['cache'] = new FakeCacheManager($cache);
        $app['mail.manager'] = $mail;
        $app['events'] = $events;
        $app['log'] = new FakeLogger;
        $this->installFacades($app);

        $manager = new ErrorAlertManager($app);
        $this->assertTrue($manager->report(new \RuntimeException('safe diagnostic')));

        $this->assertSame('transactional', $mail->selectedMailer);
        $this->assertSame(['ops@example.test'], $mail->mailer->recipients);
        $this->assertCount(1, $mail->mailer->sent);
        $mailable = $mail->mailer->sent[0];
        $mailable->build();
        $this->assertStringNotContainsString("\r", (string) $mailable->subject);
        $this->assertStringNotContainsString("\n", (string) $mailable->subject);
        $this->assertCount(1, $events->dispatched);
        $this->assertInstanceOf(AlertRequested::class, $events->dispatched[0]);
        $this->assertArrayNotHasKey('detail', $events->dispatched[0]->payload);
        $this->assertArrayNotHasKey('recipients', $events->dispatched[0]->payload);
        $this->assertArrayNotHasKey('mailer', $events->dispatched[0]->payload);
        $this->assertArrayNotHasKey('backlog_key', $events->dispatched[0]->payload);
        $this->assertArrayNotHasKey('fingerprint', $events->dispatched[0]->payload);
        $this->assertSame(0, $cache->values[$mail->mailer->sent[0]->payload['backlog_key']]);
    }

    public function test_named_mailer_fails_clearly_when_legacy_facade_has_no_manager_api(): void
    {
        $legacyMailer = new FakeLegacyMailer;
        $app = new FakeApplication(false, []);
        $app['mail.manager'] = $legacyMailer;
        $this->installFacades($app);

        $job = new SendErrorAlert([
            'mailer' => 'transactional',
            'recipients' => ['ops@example.test'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Named mailers are not supported');
        $job->handle();
    }

    public function test_event_listener_failure_does_not_block_sync_delivery(): void
    {
        $cache = new FakeCacheStore;
        $mail = new FakeMailManager;
        $events = new FakeEventDispatcher;
        $events->throwOnDispatch = true;
        $logger = new FakeLogger;
        $app = new FakeApplication(true, [
            'enabled' => true,
            'environments' => ['testing'],
            'delivery' => 'sync',
            'recipients' => ['ops@example.test'],
        ]);
        $app['cache'] = new FakeCacheManager($cache);
        $app['mail.manager'] = $mail;
        $app['events'] = $events;
        $app['log'] = $logger;
        $this->installFacades($app);

        $this->assertTrue((new ErrorAlertManager($app))->report(new \RuntimeException('diagnostic')));
        $this->assertCount(1, $mail->mailer->sent);
        $this->assertSame('error_alert_event_dispatch_failed', $logger->errors[0][0]);
        $this->assertSame(['type' => \RuntimeException::class], $logger->errors[0][1]);
    }

    public function test_queue_delivery_dispatches_a_job_after_reserving_capacity(): void
    {
        $cache = new FakeCacheStore;
        $dispatcher = new FakeBusDispatcher;
        $app = new FakeApplication(true, [
            'enabled' => true,
            'environments' => ['testing'],
            'delivery' => 'queue',
            'recipients' => ['ops@example.test'],
            'connection' => 'redis',
            'queue' => 'error-alerts',
            'encrypt_payload' => false,
            'queue.connections' => ['redis' => ['driver' => 'redis']],
        ]);
        $app['cache'] = new FakeCacheManager($cache);
        $app['Illuminate\\Contracts\\Bus\\Dispatcher'] = $dispatcher;
        $app['events'] = new FakeEventDispatcher;
        $app['log'] = new FakeLogger;
        $this->installFacades($app);

        $this->assertTrue((new ErrorAlertManager($app))->report(new \RuntimeException('queued diagnostic')));

        $this->assertCount(1, $dispatcher->jobs);
        $this->assertInstanceOf(SendErrorAlert::class, $dispatcher->jobs[0]);
        $backlogKey = $dispatcher->jobs[0]->backlogKey;
        $this->assertSame(1, $cache->values[$backlogKey]);
        $this->assertTrue($dispatcher->jobs[0]->ownsBacklogReservation);
        $dispatcher->jobs[0]->failed(new \RuntimeException('terminal failure'));
        $this->assertSame(0, $cache->values[$backlogKey]);
    }

    public function test_invalid_delivery_configuration_fails_closed_and_logs_once(): void
    {
        $logger = new FakeLogger;
        $app = new FakeApplication(true, [
            'enabled' => true,
            'environments' => ['testing'],
            'delivery' => 'typo',
        ]);
        $app['log'] = $logger;
        $manager = new ErrorAlertManager($app);

        $this->assertFalse($manager->report(new \RuntimeException('first')));
        $this->assertFalse($manager->report(new \RuntimeException('second')));
        $this->assertCount(1, $logger->errors);
        $this->assertSame('error_alert_invalid_delivery_mode', $logger->errors[0][0]);
        $this->assertSame([], $logger->errors[0][1]);
    }

    public function test_check_command_warns_for_plaintext_queue_payloads_in_production(): void
    {
        $tester = $this->checkCommand([
            'error-alert' => [
                'recipients' => ['ops@example.test'],
                'delivery' => 'queue',
                'connection' => 'redis',
                'queue' => 'error-alerts',
                'cache_store' => 'redis',
                'encrypt_payload' => false,
            ],
            'queue.default' => 'redis',
            'queue.connections' => ['redis' => ['driver' => 'redis']],
            'cache.default' => 'redis',
            'cache.stores' => ['redis' => ['driver' => 'array']],
        ], 'production');

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('WARNING:', $tester->getDisplay());
        $this->assertStringContainsString('payload encryption is disabled', $tester->getDisplay());
    }

    public function test_check_command_reads_configuration_from_its_bound_application(): void
    {
        $tester = $this->checkCommand([
            'error-alert' => [
                'recipients' => ['ops@example.test'],
                'delivery' => 'sync',
                'cache_store' => 'redis',
            ],
            'cache.default' => 'redis',
            'cache.stores' => ['redis' => ['driver' => 'array']],
        ], 'testing');

        $command = $this->lastCommand;
        $method = new \ReflectionMethod($command, 'configuration');
        $method->setAccessible(true);

        $this->assertSame(
            ['recipients' => ['ops@example.test'], 'delivery' => 'sync', 'cache_store' => 'redis'],
            $method->invoke($command, 'error-alert', [])
        );
    }

    public function test_test_command_resolves_manager_and_configuration_from_bound_application(): void
    {
        $container = new FakeLaravelContainer('testing');
        $container->instance('enggarasmoro.error-alert', new FakeTestAlertManager);
        $container->instance('config', new FakeGlobalConfig([
            'error-alert.delivery' => 'sync',
        ]));
        Container::setInstance(new Container);

        $command = new TestErrorAlertCommand;
        $command->setLaravel($container);
        $tester = new CommandTester($command);

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Test alert sent synchronously.', $tester->getDisplay());
    }

    public function test_check_command_rejects_an_unconfigured_cache_store(): void
    {
        $tester = $this->checkCommand([
            'error-alert' => [
                'recipients' => ['ops@example.test'],
                'delivery' => 'sync',
                'cache_store' => 'missing',
            ],
            'cache.default' => 'redis',
            'cache.stores' => ['redis' => ['driver' => 'array']],
        ], 'testing');

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('Configured cache store does not exist: missing', $tester->getDisplay());
    }

    public function test_mailable_sanitizes_direct_header_metadata(): void
    {
        $mail = new ErrorAlertMail([
            'service' => "inspection-api\r\nBcc: attacker@example.test",
            'source' => "http\nBcc: attacker@example.test",
        ]);

        $mail->build();

        $this->assertStringNotContainsString("\r", (string) $mail->subject);
        $this->assertStringNotContainsString("\n", (string) $mail->subject);
    }

    public function test_check_command_rejects_an_unavailable_cache_store(): void
    {
        $store = new FakeCacheStore;
        $store->throwOnGet = true;
        $tester = $this->checkCommand([
            'error-alert' => [
                'recipients' => ['ops@example.test'],
                'delivery' => 'sync',
                'cache_store' => 'redis',
            ],
            'cache.default' => 'redis',
            'cache.stores' => ['redis' => ['driver' => 'redis']],
        ], 'testing', $store);

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('Configured cache store is unavailable: redis', $tester->getDisplay());
    }

    public function test_check_command_rejects_a_readable_but_unwritable_cache_store(): void
    {
        $store = new FakeCacheStore;
        $store->throwOnIncrement = true;
        $tester = $this->checkCommand([
            'error-alert' => [
                'recipients' => ['ops@example.test'],
                'delivery' => 'sync',
                'cache_store' => 'redis',
            ],
            'cache.default' => 'redis',
            'cache.stores' => ['redis' => ['driver' => 'array']],
        ], 'testing', $store);

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('Configured cache store is unavailable: redis', $tester->getDisplay());
        $this->assertSame([], $store->values);
    }

    public function test_check_command_rejects_named_mailer_with_legacy_single_mailer_config(): void
    {
        $tester = $this->checkCommand([
            'error-alert' => [
                'recipients' => ['ops@example.test'],
                'delivery' => 'sync',
                'cache_store' => 'redis',
                'mailer' => 'transactional',
            ],
            'mail.driver' => 'smtp',
            'cache.default' => 'redis',
            'cache.stores' => ['redis' => ['driver' => 'array']],
        ], 'testing');

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('ERROR_ALERT_MAILER requires named mailer support', $tester->getDisplay());
    }

    public function test_provider_logs_only_the_registration_failure_type(): void
    {
        $logger = new FakeLogger;
        $app = new FakeApplication(false, []);
        $app['log'] = $logger;
        $provider = new ErrorAlertServiceProvider($app);
        $method = new \ReflectionMethod($provider, 'logHandlerRegistrationFailure');
        $method->setAccessible(true);
        $method->invoke($provider, new \RuntimeException('contains a secret value'));

        $this->assertSame('error_alert_exception_handler_registration_failed', $logger->warnings[0][0]);
        $this->assertSame(['type' => \RuntimeException::class], $logger->warnings[0][1]);
    }

    public function test_provider_skips_the_known_legacy_handler_capability_without_warning(): void
    {
        $logger = new FakeLogger;
        $app = new FakeLaravelContainer('testing');
        $app->instance('config', new FakeGlobalConfig([]));
        $app->instance('log', $logger);
        $app->instance('Illuminate\\Contracts\\Debug\\ExceptionHandler', new LegacyExceptionHandlerDouble);
        Container::setInstance($app);

        (new TestableErrorAlertServiceProvider($app))->boot();

        $this->assertSame([], $logger->warnings);
    }

    public function test_provider_logs_unexpected_reportable_registration_failure(): void
    {
        $logger = new FakeLogger;
        $app = new FakeLaravelContainer('testing');
        $app->instance('config', new FakeGlobalConfig([]));
        $app->instance('log', $logger);
        $app->instance('Illuminate\\Contracts\\Debug\\ExceptionHandler', new FailingReportableExceptionHandlerDouble);
        $app->instance('enggarasmoro.error-alert', new ErrorAlertManager($app));
        Container::setInstance($app);

        (new TestableErrorAlertServiceProvider($app))->boot();

        $this->assertCount(1, $logger->warnings);
        $this->assertSame('error_alert_exception_handler_registration_failed', $logger->warnings[0][0]);
        $this->assertSame(['type' => \RuntimeException::class], $logger->warnings[0][1]);
    }

    public function test_provider_resolves_config_publish_path_from_bound_application(): void
    {
        $app = new FakeLaravelContainer('testing');
        $provider = new TestableErrorAlertServiceProvider($app);
        $method = new \ReflectionMethod($provider, 'configurationPath');
        $method->setAccessible(true);

        $this->assertSame('/test/config/error-alert.php', $method->invoke($provider, 'error-alert.php'));
    }

    protected function invokePayload(ErrorAlertManager $manager, $exception, array $context = [])
    {
        $method = new \ReflectionMethod($manager, 'payload');
        $method->setAccessible(true);

        return $method->invoke($manager, $exception, $context);
    }

    protected function invokeQueueAllowed(ErrorAlertManager $manager): bool
    {
        $method = new \ReflectionMethod($manager, 'queueAllowed');
        $method->setAccessible(true);

        return (bool) $method->invoke($manager);
    }

    protected function invokeDeliveryMode(ErrorAlertManager $manager): ?string
    {
        $method = new \ReflectionMethod($manager, 'deliveryMode');
        $method->setAccessible(true);

        return $method->invoke($manager);
    }

    protected function invokeReserve(ErrorAlertManager $manager, array $payload): bool
    {
        $method = new \ReflectionMethod($manager, 'reserve');
        $method->setAccessible(true);

        return (bool) $method->invoke($manager, $payload);
    }

    protected function invokeSyncSend(ErrorAlertManager $manager, array $payload): bool
    {
        $method = new \ReflectionMethod($manager, 'sendSynchronously');
        $method->setAccessible(true);

        return (bool) $method->invoke($manager, $payload);
    }

    protected function installCache(FakeCacheStore $store): void
    {
        $app = new FakeApplication(false, []);
        $app['cache'] = new FakeCacheManager($store);
        $this->installFacades($app);
    }

    protected function installFacades(FakeApplication $app): void
    {
        Facade::setFacadeApplication($app);
        Cache::swap(isset($app['cache']) ? $app['cache'] : new FakeCacheManager(new FakeCacheStore));
        if (isset($app['mail.manager'])) {
            Mail::swap($app['mail.manager']);
        }
    }

    protected function checkCommand(array $config, $environment, ?FakeCacheStore $store = null)
    {
        $container = new FakeLaravelContainer($environment);
        $container->instance('config', new FakeGlobalConfig($config));
        $container->instance('cache', new FakeCacheManager($store ?: new FakeCacheStore));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Cache::swap($container->make('cache'));

        $command = new CheckErrorAlertCommand;
        $command->setLaravel($container);
        $this->lastCommand = $command;

        return new CommandTester($command);
    }

    protected function invokeDeliver(ErrorAlertManager $manager): bool
    {
        $method = new \ReflectionMethod($manager, 'deliver');
        $method->setAccessible(true);

        return (bool) $method->invoke($manager, ['fingerprint' => 'test']);
    }
}

class FakeApplication implements \ArrayAccess
{
    private $console;

    private $values;

    public function __construct($console, array $config)
    {
        $this->console = $console;
        $this->values = ['config' => new FakeConfig($config)];
    }

    public function environment()
    {
        return 'testing';
    }

    public function runningInConsole()
    {
        return $this->console;
    }

    public function bound($key)
    {
        return isset($this->values[$key]);
    }

    public function make($key)
    {
        if (isset($this->values[$key])) {
            return $this->values[$key];
        }

        throw new \RuntimeException('not available in this unit test');
    }

    public function instance($key, $value)
    {
        $this->values[$key] = $value;

        return $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->values[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        if (! array_key_exists($offset, $this->values)) {
            throw new \OutOfBoundsException('Missing test container value: '.$offset);
        }

        return $this->values[$offset];
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

class FakeConfig
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function get($key, $default = null)
    {
        if ($key === 'error-alert') {
            return $this->config;
        }

        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }
}

class RoutingErrorAlertManager extends ErrorAlertManager
{
    /** @var array<int, string> */
    public $calls = [];

    protected function sendSynchronously(array $payload)
    {
        $this->calls[] = 'sync';

        return true;
    }

    protected function enqueue(array $payload)
    {
        $this->calls[] = 'queue';

        return true;
    }
}

class FakeCacheManager
{
    private $store;

    public function __construct(FakeCacheStore $store)
    {
        $this->store = $store;
    }

    public function store($name = null)
    {
        return $this->store;
    }
}

class FakeCacheStore
{
    public $values = [];

    public $ttls = [];

    public $throwOnIncrement = false;

    public $throwOnGet = false;

    public function forget($key)
    {
        unset($this->values[$key], $this->ttls[$key]);

        return true;
    }

    public function get($key, $default = null)
    {
        if ($this->throwOnGet) {
            throw new \RuntimeException('cache unavailable');
        }

        return isset($this->values[$key]) ? $this->values[$key] : $default;
    }

    public function add($key, $value, $seconds)
    {
        if (array_key_exists($key, $this->values)) {
            return false;
        }

        $this->values[$key] = $value;
        $this->ttls[$key] = $seconds;

        return true;
    }

    public function increment($key, $amount = 1)
    {
        if ($this->throwOnIncrement) {
            throw new \RuntimeException('cache unavailable');
        }

        $this->values[$key] = (int) (isset($this->values[$key]) ? $this->values[$key] : 0) + $amount;

        return $this->values[$key];
    }

    public function decrement($key, $amount = 1)
    {
        $this->values[$key] = (int) (isset($this->values[$key]) ? $this->values[$key] : 0) - $amount;

        return $this->values[$key];
    }

    public function put($key, $value, $seconds = null)
    {
        $this->values[$key] = $value;
        if ($seconds !== null) {
            $this->ttls[$key] = $seconds;
        }
    }
}

class FakeMailManager
{
    public $selectedMailer;

    public $mailer;

    public function __construct()
    {
        $this->mailer = new FakeMailer;
    }

    public function mailer($name = null)
    {
        $this->selectedMailer = $name;

        return $this->mailer;
    }

    public function to($recipient)
    {
        return $this->mailer->to($recipient);
    }
}

class FakeLegacyMailer
{
    public function to($recipient)
    {
        return $this;
    }

    public function send($mailable)
    {
    }
}

class FakeTestAlertManager
{
    public function report($exception, array $context = [])
    {
        return true;
    }
}

class FakeMailer
{
    public $recipients = [];

    public $sent = [];

    public function to($recipient)
    {
        $this->recipients[] = $recipient;

        return $this;
    }

    public function send($mailable)
    {
        $this->sent[] = $mailable;
    }
}

class FakeBusDispatcher
{
    public $jobs = [];

    public function dispatch($job)
    {
        $this->jobs[] = $job;

        return $job;
    }
}

class FakeEventDispatcher
{
    public $dispatched = [];

    public $throwOnDispatch = false;

    public function dispatch($event)
    {
        $this->dispatched[] = $event;
        if ($this->throwOnDispatch) {
            throw new \RuntimeException('listener failure');
        }
    }
}

class FakeLogger
{
    public $errors = [];

    public $warnings = [];

    public function error($event, array $context = [])
    {
        $this->errors[] = [$event, $context];
    }

    public function warning($event, array $context = [])
    {
        $this->warnings[] = [$event, $context];
    }
}

class FakeLaravelContainer extends Container
{
    private $environment;

    public function __construct($environment)
    {
        $this->environment = $environment;
    }

    public function environment(...$patterns)
    {
        if ($patterns === []) {
            return $this->environment;
        }

        return in_array($this->environment, $patterns, true);
    }

    public function runningUnitTests()
    {
        return true;
    }

    public function runningInConsole()
    {
        return true;
    }

    public function configPath($path = '')
    {
        return '/test/config'.($path !== '' ? DIRECTORY_SEPARATOR.$path : '');
    }
}

class FakeGlobalConfig
{
    private $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}

class TestableErrorAlertServiceProvider extends ErrorAlertServiceProvider
{
    public function commands($commands)
    {
    }

    protected function loadViewsFrom($path, $namespace)
    {
    }

    protected function publishes(array $paths, $groups = null)
    {
    }
}

class LegacyExceptionHandlerDouble
{
}

class FailingReportableExceptionHandlerDouble
{
    public function reportable($callback)
    {
        throw new \RuntimeException('sensitive handler setup details');
    }
}
