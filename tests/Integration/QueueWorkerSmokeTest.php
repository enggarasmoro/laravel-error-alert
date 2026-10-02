<?php

namespace Enggarasmoro\LaravelErrorAlert\Tests\Integration;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase;

class QueueWorkerSmokeTest extends TestCase
{
    public function test_dedicated_core_worker_consumes_an_isolated_redis_job(): void
    {
        $appPath = getenv('ERROR_ALERT_TEST_APP_PATH');
        $queue = getenv('ERROR_ALERT_TEST_QUEUE');
        if (getenv('ERROR_ALERT_TEST_LIVE_WORKER') !== '1' || ! is_string($appPath) || ! is_string($queue)) {
            $this->markTestSkipped('Set ERROR_ALERT_TEST_LIVE_WORKER=1, ERROR_ALERT_TEST_APP_PATH, and a unique ERROR_ALERT_TEST_QUEUE.');
        }
        $this->assertSame(1, preg_match('/^inspection-core\.error-alerts\.smoke\.[a-f0-9]{16}$/', $queue));

        $bootstrap = $appPath.'/bootstrap/app.php';
        $this->assertFileExists($bootstrap);
        $app = require $bootstrap;
        $app->make(Kernel::class)->bootstrap();

        try {
            $this->assertSame($queue, config('error-alert.queue'));
            $this->assertSame(0, Queue::connection('redis')->size($queue), 'Do not run smoke against an occupied alert queue.');

            $marker = 'enggarasmoro:error-alert:worker-smoke:'.bin2hex(random_bytes(8));
            $closure = \Closure::bind(static function () use ($marker) {
                Redis::setex($marker, 60, 'done');
            }, null, null);
            $this->assertInstanceOf(\Closure::class, $closure);
            $this->assertNull((new \ReflectionFunction($closure))->getClosureScopeClass());
            $job = CallQueuedClosure::create($closure);
            $this->assertNotEmpty(Queue::connection('redis')->push($job, '', $queue));

            $deadline = microtime(true) + 15;
            while (microtime(true) < $deadline && Redis::get($marker) !== 'done') {
                usleep(100000);
            }

            $this->assertSame('done', Redis::get($marker), 'The isolated worker did not consume the smoke job.');
            $this->assertSame(0, Queue::connection('redis')->size($queue));
        } finally {
            try {
                if (isset($marker)) {
                    Redis::del($marker);
                }
                Queue::connection('redis')->clear($queue);
            } finally {
                restore_error_handler();
                restore_exception_handler();
            }
        }
    }
}
