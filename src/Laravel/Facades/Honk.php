<?php

declare(strict_types=1);

namespace Honk\Laravel\Facades;

use Closure;
use Honk\Laravel\Exceptions\ExceptionHonker;
use Honk\Laravel\HonkManager;
use Honk\Laravel\Testing\HonkFake;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @method static \Honk\Accepted honk(\Honk\Message|array<string, mixed> $message, ?string $idempotencyKey = null)
 * @method static \Honk\Accepted send(\Honk\Message|array<string, mixed> $message, ?string $idempotencyKey = null)
 * @method static \Honk\Laravel\Jobs\SendHonkMessage queue(\Honk\Message|array<string, mixed> $message, ?string $idempotencyKey = null, \DateTimeInterface|\DateInterval|int|null $delay = null)
 * @method static \Honk\Laravel\PendingDeferredHonk defer(bool $always = false)
 * @method static \Honk\Accepted uhoh(?string $groupKey, ?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted problem(?string $groupKey, ?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted phew(?string $groupKey, ?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted recovery(?string $groupKey, ?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted light(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted beep(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted loud(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted long(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted blast(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted info(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted success(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted warning(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted error(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Accepted critical(?string $title, string $message, array<string, mixed> $options = [])
 * @method static \Honk\Client client()
 * @method static \Honk\Client clientFor(mixed $route)
 * @method static list<\Honk\Message> sent(?callable $callback = null)
 * @method static list<\Honk\Message> queued(?callable $callback = null)
 * @method static void assertSent(?callable $callback = null)
 * @method static void assertSentTimes(int $times, ?callable $callback = null)
 * @method static void assertNotSent(callable $callback)
 * @method static void assertNothingSent()
 * @method static void assertQueued(callable|int|null $callback = null)
 * @method static void assertNothingQueued()
 * @method static void assertNothingOutgoing()
 *
 * @see HonkManager
 * @see HonkFake
 */
final class Honk extends Facade
{
    /** Replaces Honk with a fake that records messages instead of sending them. */
    public static function fake(): HonkFake
    {
        $fake = Container::getInstance()->make(HonkFake::class);
        static::swap($fake);

        return $fake;
    }

    /**
     * A report callback for bootstrap/app.php: honks a Long message (a problem grouped per
     * exception class and location, throttled) for every reported exception.
     *
     *     ->withExceptions(function (Exceptions $exceptions): void {
     *         $exceptions->report(Honk::reportable());
     *     })
     *
     * Resolved lazily, so it is safe to call while the exception handler is being configured.
     *
     * @param string|null $severity        horn or canonical name (default honk.exceptions.severity, "long")
     * @param int|null    $throttleSeconds one honk per exception class + location per window (default honk.exceptions.throttle)
     *
     * @return Closure(Throwable): void
     */
    public static function reportable(?string $severity = null, ?int $throttleSeconds = null): Closure
    {
        return static function (Throwable $e) use ($severity, $throttleSeconds): void {
            Container::getInstance()->make(ExceptionHonker::class)->report($e, $severity, $throttleSeconds);
        };
    }

    protected static function getFacadeAccessor(): string
    {
        return HonkManager::class;
    }
}
