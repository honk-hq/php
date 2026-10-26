<?php

declare(strict_types=1);

namespace Honk\Laravel;

use DateInterval;
use DateTimeInterface;
use Honk\Accepted;
use Honk\Client;
use Honk\Exception\HonkException;
use Honk\Helper;
use Honk\Laravel\Events\HonkFailed;
use Honk\Laravel\Events\HonkSent;
use Honk\Laravel\Jobs\SendHonkMessage;
use Honk\Laravel\Support\ContextMetadata;
use Honk\Message;
use Honk\Uuid;
use Honk\Wire;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * What the Honk facade resolves to. Every way of sending goes through deliver(), so events,
 * Context metadata and Honk::fake() behave the same for honk(), defer(), queue(), the
 * notification channel, exception reports and scheduler hooks.
 *
 * - honk() (or send()): now, in this process (retries within the call; up to the configured deadline).
 * - defer(): after the HTTP response is sent (or the command/job finishes), no queue needed.
 * - queue(): from a queue worker; survives restarts and retries with the same key.
 */
#[Singleton]
class HonkManager
{
    private ?Client $client = null;

    /**
     * @param array<string, mixed> $config config/honk.php
     */
    public function __construct(
        #[Config('honk')] protected readonly array $config,
        protected readonly Dispatcher $events,
        protected readonly ContextMetadata $context,
    ) {
    }

    /** The client built from config/honk.php (created on first use). */
    public function client(): Client
    {
        return $this->client ??= self::makeClient($this->config);
    }

    /**
     * The client for a notification route: null uses the default; a string is another ingestion
     * key on the same server; an array overrides config keys (url, key, defaults, …); a Client is
     * used as is.
     */
    public function clientFor(mixed $route): Client
    {
        return match (true) {
            $route === null => $this->client(),
            $route instanceof Client => $route,
            is_string($route) => self::makeClient(['key' => $route] + $this->config),
            is_array($route) => self::makeClient(self::stringKeys($route) + $this->config),
            default => throw new InvalidArgumentException('Honk: routeNotificationForHonk() must return null, false, an ingestion key, an array of config overrides or a Honk\Client'),
        };
    }

    /**
     * Sends now, in this process. Inside web requests prefer defer() or queue().
     *
     * @param Message|array<string, mixed> $message
     */
    public function honk(Message|array $message, ?string $idempotencyKey = null): Accepted
    {
        [$message, $key] = $this->prepare($message, $idempotencyKey);

        return $this->deliver($message, $key, null, 'send');
    }

    /**
     * Same as honk().
     *
     * @param Message|array<string, mixed> $message
     */
    public function send(Message|array $message, ?string $idempotencyKey = null): Accepted
    {
        return $this->honk($message, $idempotencyKey);
    }

    /** Sends to a notification route (used by HonkChannel). */
    public function sendTo(mixed $route, Message $message, ?string $idempotencyKey = null): Accepted
    {
        [$message, $key] = $this->prepare($message, $idempotencyKey);

        return $this->deliver($message, $key, $route, 'notification');
    }

    /**
     * Checks the message now (a message or a title; mistakes surface in your code, not in the worker), fixes its
     * idempotency key, defaults and Context metadata, and dispatches a SendHonkMessage job.
     * Its retries reuse the key, so they never create duplicates. Connection and queue come from
     * Queue::route() (set from honk.queue.connection / honk.queue.queue).
     *
     * @param Message|array<string, mixed> $message
     */
    public function queue(Message|array $message, ?string $idempotencyKey = null, DateTimeInterface|DateInterval|int|null $delay = null): SendHonkMessage
    {
        [$message, $key] = $this->prepare($message, $idempotencyKey);
        $job = new SendHonkMessage($this->payload($message), $key);
        if ($delay !== null) {
            $job->delay($delay);
        }
        $this->dispatchJob($job);

        return $job;
    }

    /**
     * Sends after the response: `Honk::defer()->loud('Disk 91%', '/var on app-01')`. The message
     * is checked now. By default it is skipped when the request fails (4xx/5xx), like any
     * Laravel deferred function; pass always: true to send regardless.
     */
    public function defer(bool $always = false): PendingDeferredHonk
    {
        return new PendingDeferredHonk($this, $always);
    }

    /**
     * @internal used by PendingDeferredHonk
     *
     * @param Message|array<string, mixed> $message
     */
    public function deferSend(Message|array $message, ?string $idempotencyKey, bool $always): void
    {
        [$message, $key] = $this->prepare($message, $idempotencyKey);
        Wire::build($message, $this->defaults());
        defer(function () use ($message, $key): void {
            // Never throw from a deferred callback: the response is already sent, and a failure
            // here must not loop back through exception reporting.
            try {
                $this->deliver($message, $key, null, 'defer');
            } catch (HonkException $e) {
                report($e); // logged; HonkFailed was dispatched; never honked about (see ExceptionHonker)
            } catch (Throwable $e) {
                logger()->warning('Honk deferred send failed: ' . $e->getMessage());
            }
        }, 'honk:' . $key, $always);
    }

    /**
     * Sends a prepared message, logs what the server corrected (honk.log_warnings) and dispatches
     * HonkSent / HonkFailed.
     *
     * @internal used by the queued job, the channel and this class
     */
    public function deliver(Message $message, string $idempotencyKey, mixed $route, string $via): Accepted
    {
        try {
            $accepted = $this->clientFor($route)->honk($message, $idempotencyKey);
        } catch (HonkException $e) {
            $this->events->dispatch(new HonkFailed($message, $e, $idempotencyKey, $via));
            throw $e;
        }
        $this->logWarnings($accepted, $idempotencyKey);
        $this->events->dispatch(new HonkSent($message, $accepted, $idempotencyKey, $via));

        return $accepted;
    }

    /**
     * One warning-level log entry per correction the server made (unless honk.log_warnings is
     * false). Logging never fails a delivered send.
     */
    protected function logWarnings(Accepted $accepted, string $idempotencyKey): void
    {
        if (!$accepted->hasWarnings() || ($this->config['log_warnings'] ?? true) === false) {
            return;
        }
        try {
            foreach ($accepted->warnings as $warning) {
                logger()->warning('Honk corrected a message: ' . $warning, [
                    'field' => $warning->field,
                    'code' => $warning->code,
                    'message_id' => $accepted->id,
                    'idempotency_key' => $idempotencyKey,
                ]);
            }
        } catch (Throwable) {
            // The message is stored; logging must not turn that into an error.
        }
    }

    /**
     * A problem for $groupKey (opens or continues its incident; the group key is optional); a Long message unless
     * $options['severity'] says otherwise.
     *
     * @param array<string, mixed> $options
     */
    public function uhoh(?string $groupKey, ?string $title, string $message, array $options = []): Accepted
    {
        return $this->honk(Helper::problem($groupKey, $title, $message, $options));
    }

    /**
     * Same as uhoh().
     *
     * @param array<string, mixed> $options
     */
    public function problem(?string $groupKey, ?string $title, string $message, array $options = []): Accepted
    {
        return $this->uhoh($groupKey, $title, $message, $options);
    }

    /**
     * A recovery for $groupKey (closes its open incident); a Beep message unless $options['severity']
     * says otherwise.
     *
     * @param array<string, mixed> $options
     */
    public function phew(?string $groupKey, ?string $title, string $message, array $options = []): Accepted
    {
        return $this->honk(Helper::recovery($groupKey, $title, $message, $options));
    }

    /**
     * Same as phew().
     *
     * @param array<string, mixed> $options
     */
    public function recovery(?string $groupKey, ?string $title, string $message, array $options = []): Accepted
    {
        return $this->phew($groupKey, $title, $message, $options);
    }

    /**
     * A Light message (info).
     *
     * @param array<string, mixed> $options
     */
    public function light(?string $title, string $message, array $options = []): Accepted
    {
        return $this->honk(Helper::severity('info', $title, $message, $options));
    }

    /**
     * A Beep message (success).
     *
     * @param array<string, mixed> $options
     */
    public function beep(?string $title, string $message, array $options = []): Accepted
    {
        return $this->honk(Helper::severity('success', $title, $message, $options));
    }

    /**
     * A Loud message (warning).
     *
     * @param array<string, mixed> $options
     */
    public function loud(?string $title, string $message, array $options = []): Accepted
    {
        return $this->honk(Helper::severity('warning', $title, $message, $options));
    }

    /**
     * A Long message (error).
     *
     * @param array<string, mixed> $options
     */
    public function long(?string $title, string $message, array $options = []): Accepted
    {
        return $this->honk(Helper::severity('error', $title, $message, $options));
    }

    /**
     * A Blast message (critical).
     *
     * @param array<string, mixed> $options
     */
    public function blast(?string $title, string $message, array $options = []): Accepted
    {
        return $this->honk(Helper::severity('critical', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function info(?string $title, string $message, array $options = []): Accepted
    {
        return $this->light($title, $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function success(?string $title, string $message, array $options = []): Accepted
    {
        return $this->beep($title, $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function warning(?string $title, string $message, array $options = []): Accepted
    {
        return $this->loud($title, $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function error(?string $title, string $message, array $options = []): Accepted
    {
        return $this->long($title, $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function critical(?string $title, string $message, array $options = []): Accepted
    {
        return $this->blast($title, $message, $options);
    }

    /** @param array<string, mixed> $config */
    public static function makeClient(array $config): Client
    {
        return new Client(
            url: self::string($config['url'] ?? null),
            key: self::string($config['key'] ?? null),
            timeoutMs: (int) round(self::number($config['timeout'] ?? null, 5) * 1000),
            retries: (int) self::number($config['retries'] ?? null, 4),
            deadlineMs: (int) round(self::number($config['deadline'] ?? null, 30) * 1000),
            defaults: self::defaultsFrom($config),
            userAgent: 'laravel',
        );
    }

    protected function dispatchJob(SendHonkMessage $job): void
    {
        dispatch($job);
    }

    /**
     * Converts arrays, adds Context metadata and fixes the idempotency key.
     *
     * @param Message|array<string, mixed> $message
     *
     * @return array{Message, string}
     */
    protected function prepare(Message|array $message, ?string $idempotencyKey): array
    {
        $message = is_array($message) ? Message::fromArray($message) : $message;
        $key = Wire::checkIdempotencyKey($idempotencyKey ?? $message->idempotencyKey ?? Uuid::v7());

        return [$this->context->apply($message), $key];
    }

    /**
     * The message as camelCase fields with defaults applied (what the job stores).
     *
     * @return array<string, mixed>
     */
    protected function payload(Message $message): array
    {
        $fields = array_flip(Message::FIELDS);
        $payload = [];
        foreach (Wire::build($message, $this->defaults()) as $wire => $value) {
            $payload[$fields[$wire]] = $value;
        }

        return $payload;
    }

    /** @return array<string, string> */
    protected function defaults(): array
    {
        return self::defaultsFrom($this->config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, string>
     */
    private static function defaultsFrom(array $config): array
    {
        $defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
        $out = [];
        foreach (['source', 'environment', 'channel'] as $field) {
            if (is_string($defaults[$field] ?? null) && $defaults[$field] !== '') {
                $out[$field] = $defaults[$field];
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function number(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
