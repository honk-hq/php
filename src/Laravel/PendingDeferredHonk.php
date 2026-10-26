<?php

declare(strict_types=1);

namespace Honk\Laravel;

use Honk\Helper;
use Honk\Message;

/**
 * Returned by Honk::defer(): the same helpers (honk, uhoh, phew, light … blast), sent after the
 * response via Laravel's defer().
 */
final class PendingDeferredHonk
{
    public function __construct(private readonly HonkManager $manager, private readonly bool $always = false)
    {
    }

    /** @param Message|array<string, mixed> $message */
    public function honk(Message|array $message, ?string $idempotencyKey = null): void
    {
        $this->manager->deferSend($message, $idempotencyKey, $this->always);
    }

    /**
     * Same as honk().
     *
     * @param Message|array<string, mixed> $message
     */
    public function send(Message|array $message, ?string $idempotencyKey = null): void
    {
        $this->honk($message, $idempotencyKey);
    }

    /** @param array<string, mixed> $options */
    public function uhoh(?string $groupKey, ?string $title, string $message, array $options = []): void
    {
        $this->honk(Helper::problem($groupKey, $title, $message, $options));
    }

    /**
     * Same as uhoh().
     *
     * @param array<string, mixed> $options
     */
    public function problem(?string $groupKey, ?string $title, string $message, array $options = []): void
    {
        $this->uhoh($groupKey, $title, $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function phew(?string $groupKey, ?string $title, string $message, array $options = []): void
    {
        $this->honk(Helper::recovery($groupKey, $title, $message, $options));
    }

    /**
     * Same as phew().
     *
     * @param array<string, mixed> $options
     */
    public function recovery(?string $groupKey, ?string $title, string $message, array $options = []): void
    {
        $this->phew($groupKey, $title, $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function light(?string $title, string $message, array $options = []): void
    {
        $this->honk(Helper::severity('info', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function beep(?string $title, string $message, array $options = []): void
    {
        $this->honk(Helper::severity('success', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function loud(?string $title, string $message, array $options = []): void
    {
        $this->honk(Helper::severity('warning', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function long(?string $title, string $message, array $options = []): void
    {
        $this->honk(Helper::severity('error', $title, $message, $options));
    }

    /** @param array<string, mixed> $options */
    public function blast(?string $title, string $message, array $options = []): void
    {
        $this->honk(Helper::severity('critical', $title, $message, $options));
    }
}
