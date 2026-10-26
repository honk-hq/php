<?php

declare(strict_types=1);

namespace Honk\Tests\Laravel;

use Honk\Laravel\Events\HonkSent;
use Honk\Laravel\Facades\Honk;
use Honk\Laravel\HonkManager;
use Honk\Laravel\Jobs\SendHonkMessage;
use Honk\Tests\Support\MockServer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

final class WarningsTest extends TestCase
{
    private function acceptWithWarnings(): void
    {
        $this->server->script([['status' => 202, 'body' => MockServer::accepted()['body'] + ['warnings' => [
            ['field' => 'title', 'code' => 'truncated', 'message' => 'shortened to 160 characters'],
            ['field' => 'url', 'code' => 'ignored', 'message' => 'not an https URL; left out'],
        ]]]]);
    }

    public function testEachWarningIsLoggedAndOnHonkSent(): void
    {
        $this->acceptWithWarnings();
        Log::spy();
        Event::fake([HonkSent::class]);

        $accepted = Honk::loud(str_repeat('t', 200), 'Disk 91%', ['url' => 'http://example.com', 'idempotencyKey' => 'disk-1']);

        $this->assertSame(['title', 'url'], array_map(static fn ($w) => $w->field, $accepted->warnings));
        Log::shouldHaveReceived('warning')->with('Honk corrected a message: title truncated: shortened to 160 characters', [
            'field' => 'title', 'code' => 'truncated', 'message_id' => 'msg_01k6h3w4z5x6y7z8a9b0c1d2e3', 'idempotency_key' => 'disk-1',
        ])->once();
        Log::shouldHaveReceived('warning')->with('Honk corrected a message: url ignored: not an https URL; left out', \Mockery::type('array'))->once();
        Event::assertDispatched(HonkSent::class, fn (HonkSent $e) => count($e->accepted->warnings) === 2);
    }

    public function testQueuedSendsLogWarningsToo(): void
    {
        $this->acceptWithWarnings();
        Log::spy();
        (new SendHonkMessage(['message' => 'x', 'url' => 'http://example.com'], 'q-1'))->handle(app(HonkManager::class));
        Log::shouldHaveReceived('warning')->twice();
    }

    public function testLoggingCanBeTurnedOff(): void
    {
        config(['honk.log_warnings' => false]);
        $this->app->forgetInstance(HonkManager::class);
        Honk::clearResolvedInstances();
        $this->acceptWithWarnings();
        Log::spy();

        $accepted = Honk::send(['message' => 'x']);

        $this->assertCount(2, $accepted->warnings);
        Log::shouldNotHaveReceived('warning');
    }

    public function testNoWarningsNoLog(): void
    {
        Log::spy();
        $this->assertSame([], Honk::send(['message' => 'x'])->warnings);
        Log::shouldNotHaveReceived('warning');
    }
}
