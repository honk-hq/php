<?php

declare(strict_types=1);

namespace Honk\Tests\Laravel;

use Honk\Exception\ValidationException;
use Honk\Laravel\Facades\Honk;
use Illuminate\Support\Facades\Route;

final class DeferTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/ok', function () {
            Honk::defer()->loud('Disk 91%', '/var on app-01');

            return response('done');
        });
        Route::get('/fails', function () {
            Honk::defer()->long('Skipped', 'request failed');
            Honk::defer(always: true)->blast('Always', 'sent even though the request failed');
            abort(500);
        });
    }

    public function testDeferredHonkIsSentAfterTheResponse(): void
    {
        $this->get('/ok')->assertOk()->assertSee('done');
        $requests = $this->server->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('warning', json_decode($requests[0]['body'], true)['severity']);
    }

    public function testFailedRequestsSkipDeferredHonksUnlessAlways(): void
    {
        $this->get('/fails')->assertStatus(500);
        $sent = array_map(static fn ($r) => json_decode($r['body'], true)['title'], $this->server->requests());
        $this->assertSame(['Always'], $sent);
    }

    public function testDeferredMessagesAreValidatedImmediately(): void
    {
        $this->expectException(ValidationException::class);
        Honk::defer()->loud('Bad', 'link', ['url' => 'ftp://example.com']);
    }

    public function testDeferredUhohPhewAndHonkMatchTheOldNames(): void
    {
        $this->withoutDefer();
        Honk::defer()->uhoh('kitchen/oven', 'The cake is burning', 'Smoke in the kitchen', ['idempotencyKey' => 'oven-1']);
        Honk::defer()->problem('kitchen/oven', 'The cake is burning', 'Smoke in the kitchen', ['idempotencyKey' => 'oven-1']);
        Honk::defer()->phew('kitchen/oven', 'Cake saved', 'Nobody needs to know.', ['idempotencyKey' => 'oven-2']);
        Honk::defer()->recovery('kitchen/oven', 'Cake saved', 'Nobody needs to know.', ['idempotencyKey' => 'oven-2']);
        Honk::defer()->honk(['message' => 'Someone bought 3 rubber chickens'], 'order-1042');
        Honk::defer()->send(['message' => 'Someone bought 3 rubber chickens'], 'order-1042');
        $r = array_map(static fn (array $x) => [$x['headers']['idempotency-key'], json_decode($x['body'], true)], $this->server->requests());
        $this->assertCount(6, $r);
        $this->assertSame($r[0], $r[1]);
        $this->assertSame($r[2], $r[3]);
        $this->assertSame($r[4], $r[5]);
        $this->assertSame(['problem', 'error'], [$r[0][1]['event_type'], $r[0][1]['severity']]);
        $this->assertSame(['recovery', 'success'], [$r[2][1]['event_type'], $r[2][1]['severity']]);
    }

    public function testWithoutDeferSendsAtOnce(): void
    {
        $this->withoutDefer();
        Honk::defer()->beep('Now', 'immediately');
        $this->assertCount(1, $this->server->requests());
    }
}
