<?php

declare(strict_types=1);

namespace Honk\Tests\Laravel;

use Honk\Laravel\Facades\Honk;
use Honk\Laravel\HonkServiceProvider;
use Honk\Tests\Support\MockServer;
use Honk\Tests\Unit\ClientTest;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected MockServer $server;

    protected function setUp(): void
    {
        $this->server = MockServer::get();
        $this->server->script([MockServer::accepted()]);
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [HonkServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Honk' => Honk::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('honk.url', $this->server->url);
        $app['config']->set('honk.key', ClientTest::KEY);
        $app['config']->set('honk.retries', 0);
    }

    /** @return array<string, mixed> */
    protected function lastBody(): array
    {
        $r = $this->server->requests();

        return json_decode(end($r)['body'], true);
    }
}
