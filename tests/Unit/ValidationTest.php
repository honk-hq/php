<?php

declare(strict_types=1);

namespace Honk\Tests\Unit;

use DateTimeImmutable;
use Honk\Client;
use Honk\Exception\ValidationException;
use Honk\Message;
use Honk\Uuid;
use Honk\Wire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalid(): iterable
    {
        yield 'no message and no title' => [[], 'message', 'required'];
        yield 'blank message, no title' => [['message' => '   '], 'message', 'required'];
        yield 'blank message and title' => [['message' => '', 'title' => " \t"], 'message', 'required'];
        yield 'snake case' => [['message' => 'x', 'group_key' => 'g'], 'group_key', 'not_allowed'];
        yield 'wrong type' => [['message' => 'x', 'ttlSeconds' => 'soon'], 'ttl_seconds', 'invalid_format'];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('invalid')]
    public function testRejectsLocally(array $fields, string $field, string $code): void
    {
        $client = new Client(url: 'https://honk.example.com', key: ClientTest::KEY);
        try {
            $client->send($fields);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertTrue($e->local);
            $this->assertSame(0, $e->attempts);
            $found = array_filter($e->fields, static fn ($f) => $f->field === $field && $f->code === $code);
            $this->assertNotEmpty($found, "want {$field}/{$code}, got " . $e->getMessage());
        }
    }

    public function testMissingMessageAndTitleMessage(): void
    {
        $this->expectExceptionMessage('Invalid Honk message: message required: a message or a title is required');
        Wire::build(Message::make());
    }

    public function testSnakeCaseGetsAHint(): void
    {
        $this->expectExceptionMessage('group_key not_allowed: unknown field (use groupKey)');
        Message::fromArray(['message' => 'x', 'group_key' => 'g']);
    }

    /** The server owns the field rules: values it would correct are sent as given. */
    public function testFieldContentsAreSentAsGiven(): void
    {
        $actions = [['title' => str_repeat('t', 41), 'url' => 'javascript:alert(1)'], ['url' => 'ftp://x'], ['title' => 'a'], ['title' => 'b', 'url' => 'tel:1']];
        $body = Wire::build(Message::fromArray([
            'message' => str_repeat('x', 9000),
            'title' => "two\nlines " . str_repeat('t', 200),
            'severity' => 'fatal',
            'priority' => 'asap',
            'category' => 'crm',
            'eventType' => 'recovery',
            'sourceSequence' => -1,
            'url' => 'http://user:pw@example.com',
            'imageUrl' => 'https://cdn.example.com/a.jpg#x',
            'occurredAt' => '2026-10-02 10:00',
            'metadata' => ['bad key' => 1, 'nested' => ['a' => 1]],
            'ttlSeconds' => 5,
            'actions' => $actions,
        ]));
        $this->assertSame('fatal', $body['severity']);
        $this->assertSame('recovery', $body['event_type']);
        $this->assertArrayNotHasKey('group_key', $body);
        $this->assertSame(-1, $body['source_sequence']);
        $this->assertSame(5, $body['ttl_seconds']);
        $this->assertSame($actions, $body['actions']);
        $this->assertSame(9000, strlen($body['message']));
    }

    public function testTitleAloneIsEnough(): void
    {
        $this->assertSame(['title' => 'Backup finished'], Wire::build(Message::make(null, 'Backup finished')));
    }

    public function testUnencodableBodyIsRejectedLocally(): void
    {
        try {
            Wire::encode(Wire::build(Message::make("bad \xFF utf8")));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('body', $e->fields[0]->field);
        }
    }

    public function testCarbonLikeDatesAreUtcWithMilliseconds(): void
    {
        $body = Wire::build(Message::make('x')->occurredAt(new DateTimeImmutable('2026-10-02T00:10:00.5+03:00')));
        $this->assertSame('2026-10-01T21:10:00.500Z', $body['occurred_at']);
    }

    public function testInvalidIdempotencyKeys(): void
    {
        $client = new Client(url: 'https://honk.example.com', key: ClientTest::KEY);
        foreach (['', 'has space', str_repeat('x', 129), 'ünicode'] as $key) {
            try {
                $client->send(['message' => 'x'], $key);
                $this->fail("expected ValidationException for '{$key}'");
            } catch (ValidationException $e) {
                $this->assertSame('Idempotency-Key', $e->fields[0]->field);
            }
        }
    }

    public function testMessageBuilder(): void
    {
        $m = Message::make()->line('Customer: Ana')->line('Company: Acme')->title('New request');
        $this->assertSame(['title' => 'New request', 'message' => "Customer: Ana\nCompany: Acme"], $m->toArray());
        $this->assertSame(['message' => 'x', 'metadata' => ['a' => 1, 'b' => 2]], Message::make('x')->metadata(['a' => 1])->meta('b', 2)->toArray());
        $this->assertSame(
            ['message' => 'x', 'actions' => [['title' => 'Reply', 'url' => 'mailto:ana@acme.example'], ['title' => 'Call', 'url' => 'tel:+15550134']]],
            Message::make('x')->action('Reply', 'mailto:ana@acme.example')->action('Call', 'tel:+15550134')->toArray(),
        );
        $this->assertSame([['title' => 'Call', 'url' => 'tel:+15550134']], Message::make('x')->action('Reply', 'mailto:ana@acme.example')->actions([['title' => 'Call', 'url' => 'tel:+15550134']])->actions);
    }

    public function testUuidV7(): void
    {
        $a = Uuid::v7(1_700_000_000_000);
        $b = Uuid::v7(1_700_000_000_001);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
        $this->assertLessThan($b, $a);
        $this->assertSame('018bcfe56800', str_replace('-', '', substr($a, 0, 13)));
    }
}
