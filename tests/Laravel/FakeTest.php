<?php

declare(strict_types=1);

namespace Honk\Tests\Laravel;

use Honk\Exception\ValidationException;
use Honk\Laravel\Facades\Honk;
use Honk\Laravel\HonkManager;
use Honk\Laravel\Notifications\HonkMessage;
use Honk\Laravel\Testing\HonkFake;
use Honk\Message;
use Honk\Severity;
use PHPUnit\Framework\AssertionFailedError;

final class FakeTest extends TestCase
{
    public function testFakeRecordsEveryKindOfSendWithoutTouchingTheNetwork(): void
    {
        $fake = Honk::fake();
        $this->assertInstanceOf(HonkFake::class, $fake);
        $this->assertSame($fake, app(HonkManager::class));

        $accepted = Honk::loud('Disk 91%', '/var on app-01', ['groupKey' => 'disk/var']);
        $this->assertStringStartsWith('msg_fake', $accepted->id);
        Honk::send(HonkMessage::create('Ana asked for a quote')->title('New request')->groupKey('requests/1'), 'request-1');
        Honk::defer()->blast('Payments down', 'Stripe 500s');
        Honk::problem('db/backup', 'Backup failed', 'exit 1');
        Honk::queue(['message' => 'queued one', 'severity' => 'BEEP'], 'queued-1');

        Honk::assertSentTimes(4);
        Honk::assertSent(fn (Message $m) => $m->severity === Severity::Loud && $m->groupKey === 'disk/var');
        Honk::assertSent(fn (Message $m, string $key) => $m->groupKey === 'requests/1' && $key === 'request-1');
        Honk::assertSent(fn (Message $m) => $m->severity === Severity::Blast);
        Honk::assertSent(fn (Message $m) => $m->eventType === 'problem' && $m->severity === Severity::Long);
        Honk::assertNotSent(fn (Message $m) => $m->message === 'queued one');
        Honk::assertQueued(1);
        Honk::assertQueued(fn (Message $m, string $key) => $m->severity === Severity::Beep && $key === 'queued-1');
        $this->assertCount(4, Honk::sent());
        $this->assertSame([], $this->server->requests());
    }

    public function testFakeRecordsHonkUhohAndPhewLikeTheOldNames(): void
    {
        Honk::fake();
        Honk::honk(['message' => 'Someone bought 3 rubber chickens'], 'order-1042');
        Honk::uhoh('kitchen/oven', 'The cake is burning', 'Smoke in the kitchen');
        Honk::defer()->phew('kitchen/oven', 'Cake saved', 'Nobody needs to know.');
        Honk::send(['message' => 'Someone bought 3 rubber chickens'], 'order-1042');
        Honk::problem('kitchen/oven', 'The cake is burning', 'Smoke in the kitchen');
        Honk::defer()->recovery('kitchen/oven', 'Cake saved', 'Nobody needs to know.');

        Honk::assertSentTimes(2, fn (Message $m, string $key) => $m->message === 'Someone bought 3 rubber chickens' && $key === 'order-1042');
        Honk::assertSentTimes(2, fn (Message $m) => $m->groupKey === 'kitchen/oven' && $m->eventType === 'problem' && $m->severity === Severity::Long);
        Honk::assertSentTimes(2, fn (Message $m) => $m->groupKey === 'kitchen/oven' && $m->eventType === 'recovery' && $m->severity === Severity::Beep);
        $sent = Honk::sent();
        $this->assertEquals([$sent[0], $sent[1], $sent[2]], [$sent[3], $sent[4], $sent[5]]);
    }

    public function testRecordedMessagesHaveDefaultsApplied(): void
    {
        Honk::fake();
        Honk::light(null, 'x');
        Honk::assertSent(fn (Message $m) => $m->source === 'laravel' && $m->environment === 'testing' && $m->title === null);
    }

    public function testRecordedMessagesKeepTheirActions(): void
    {
        Honk::fake();
        Honk::send(['message' => 'Ana asked for a quote', 'actions' => [['title' => 'Call Ana', 'url' => 'tel:+15550134']]]);
        Honk::defer()->light('New request', 'Ana asked for a quote', ['actions' => [['title' => 'Reply', 'url' => 'mailto:ana@acme.example']]]);
        Honk::assertSent(fn (Message $m) => $m->actions === [['title' => 'Call Ana', 'url' => 'tel:+15550134']]);
        Honk::assertSent(fn (Message $m) => ($m->actions[0]['url'] ?? null) === 'mailto:ana@acme.example');
    }

    public function testInvalidMessagesStillThrow(): void
    {
        Honk::fake();
        $this->expectException(ValidationException::class);
        Honk::send(['message' => 'x', 'url' => 'http://insecure.example.com']);
    }

    public function testNothingAssertionsAndFailures(): void
    {
        Honk::fake();
        Honk::assertNothingSent();
        Honk::assertNothingQueued();
        Honk::assertNothingOutgoing();

        Honk::beep('a', 'b');
        $this->assertFails(fn () => Honk::assertNothingSent());
        $this->assertFails(fn () => Honk::assertSentTimes(2));
        $this->assertFails(fn () => Honk::assertSent(fn (Message $m) => $m->title === 'nope'));
        $this->assertFails(fn () => Honk::assertQueued());
        $this->assertFails(fn () => Honk::assertNotSent(fn (Message $m) => $m->title === 'a'));
    }

    private function assertFails(callable $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Expected the assertion to fail.');
    }
}
