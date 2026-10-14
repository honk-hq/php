<?php

declare(strict_types=1);

namespace Honk\Tests\Laravel;

use Honk\Client;
use Honk\Laravel\Facades\Honk;
use Honk\Laravel\HonkManager;
use Honk\Laravel\Notifications\HonkMessage;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class ServiceProviderTest extends TestCase
{
    public function testConfigIsMergedWithSensibleDefaults(): void
    {
        $this->assertSame(5.0, config('honk.timeout'));
        $this->assertSame(30.0, config('honk.deadline'));
        $this->assertSame('laravel', config('honk.defaults.source'));
        $this->assertSame('testing', config('honk.defaults.environment'));
        $this->assertNull(config('honk.queue.tries')); // the job's #[Tries(5)] applies
        $this->assertFalse(config('honk.context.enabled'));
        $this->assertSame('long', config('honk.exceptions.severity'));
    }

    public function testFacadeSendsWithConfigDefaults(): void
    {
        $res = Honk::send(HonkMessage::make('Ana asked for a quote')->title('New request')->priority('high'));
        $this->assertSame('msg_01k6h3w4z5x6y7z8a9b0c1d2e3', $res->id);
        $this->assertSame(['title' => 'New request', 'message' => 'Ana asked for a quote', 'priority' => 'high', 'source' => 'laravel', 'environment' => 'testing'], $this->lastBody());
        $this->assertStringStartsWith('honk-me-php/' . Client::VERSION . ' laravel', $this->server->requests()[0]['headers']['user-agent']);
    }

    public function testHelpersThroughTheFacade(): void
    {
        Honk::problem('queue/failed', 'Jobs failing', '43 failed jobs', ['channel' => 'queue']);
        $this->assertSame(['title' => 'Jobs failing', 'message' => '43 failed jobs', 'severity' => 'error', 'source' => 'laravel', 'environment' => 'testing', 'channel' => 'queue', 'group_key' => 'queue/failed', 'event_type' => 'problem'], $this->lastBody());
    }

    public function testUhohAndPhewThroughTheFacade(): void
    {
        Honk::uhoh('kitchen/oven', 'The cake is burning', 'Smoke in the kitchen');
        $this->assertSame(['title' => 'The cake is burning', 'message' => 'Smoke in the kitchen', 'severity' => 'error', 'source' => 'laravel', 'environment' => 'testing', 'group_key' => 'kitchen/oven', 'event_type' => 'problem'], $this->lastBody());
        Honk::phew('kitchen/oven', 'Cake saved', 'Nobody needs to know.');
        $this->assertSame(['title' => 'Cake saved', 'message' => 'Nobody needs to know.', 'severity' => 'success', 'source' => 'laravel', 'environment' => 'testing', 'group_key' => 'kitchen/oven', 'event_type' => 'recovery'], $this->lastBody());
        $res = Honk::honk(['message' => 'Someone bought 3 rubber chickens', 'severity' => 'beep']);
        $this->assertSame('msg_01k6h3w4z5x6y7z8a9b0c1d2e3', $res->id);
        $this->assertSame(['message' => 'Someone bought 3 rubber chickens', 'severity' => 'success', 'source' => 'laravel', 'environment' => 'testing'], $this->lastBody());
    }

    public function testOldNamesThroughTheFacadeSendIdenticalRequests(): void
    {
        Honk::honk(HonkMessage::make('Someone bought 3 rubber chickens')->beep(), 'order-1042');
        Honk::send(HonkMessage::make('Someone bought 3 rubber chickens')->beep(), 'order-1042');
        Honk::uhoh('kitchen/oven', 'The cake is burning', 'Smoke in the kitchen', ['idempotencyKey' => 'oven-1']);
        Honk::problem('kitchen/oven', 'The cake is burning', 'Smoke in the kitchen', ['idempotencyKey' => 'oven-1']);
        Honk::phew('kitchen/oven', 'Cake saved', 'Nobody needs to know.', ['idempotencyKey' => 'oven-2']);
        Honk::recovery('kitchen/oven', 'Cake saved', 'Nobody needs to know.', ['idempotencyKey' => 'oven-2']);
        $r = array_map(static function (array $request): array {
            unset($request['at']);

            return $request;
        }, $this->server->requests());
        $this->assertCount(6, $r);
        $this->assertSame($r[0], $r[1]);
        $this->assertSame($r[2], $r[3]);
        $this->assertSame($r[4], $r[5]);
        $this->assertSame(['order-1042', 'order-1042', 'oven-1', 'oven-1', 'oven-2', 'oven-2'], array_map(static fn ($x) => $x['headers']['idempotency-key'], $r));
    }

    public function testHornHelpersThroughTheFacade(): void
    {
        Honk::loud('Disk 91%', '/var on app-01');
        Honk::blast('Payments down', 'Stripe answers 500');
        $sent = array_map(static fn ($r) => json_decode($r['body'], true)['severity'], $this->server->requests());
        $this->assertSame(['warning', 'critical'], $sent);
    }

    public function testContainerBindings(): void
    {
        $this->assertSame(app(HonkManager::class), app('honk'));
        $this->assertInstanceOf(Client::class, app(Client::class));
    }

    public function testConfigValuesAreConverted(): void
    {
        config(['honk.timeout' => 2.5, 'honk.deadline' => 10, 'honk.retries' => 2]);
        $client = HonkManager::makeClient(config('honk'));
        $this->assertSame(2500, $client->timeoutMs);
        $this->assertSame(10000, $client->deadlineMs);
        $this->assertSame(2, $client->retries);
    }

    public function testMissingKeyFailsClearly(): void
    {
        config(['honk.key' => null]);
        app()->forgetInstance(HonkManager::class);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance(HonkManager::class);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HONK_KEY');
        Honk::send(['message' => 'x']);
    }

    public function testConfigCanBePublished(): void
    {
        $target = config_path('honk.php');
        File::delete($target);
        $this->artisan('vendor:publish', ['--tag' => 'honk-config'])->assertSuccessful();
        $this->assertFileExists($target);
        $this->assertStringContainsString("env('HONK_KEY')", File::get($target));
        File::delete($target);
    }
}
