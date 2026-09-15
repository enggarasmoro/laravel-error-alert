<?php

namespace Enggarasmoro\LaravelErrorAlert\Tests\Unit;

use Enggarasmoro\LaravelErrorAlert\ErrorAlertManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ErrorAlertManagerTest extends TestCase
{
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
        return false;
    }

    public function make($key)
    {
        throw new \RuntimeException('not available in this unit test');
    }

    public function offsetExists($offset)
    {
        return isset($this->values[$offset]);
    }

    public function offsetGet($offset)
    {
        return $this->values[$offset];
    }

    public function offsetSet($offset, $value)
    {
        $this->values[$offset] = $value;
    }

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
        return $key === 'error-alert' ? $this->config : $default;
    }
}
