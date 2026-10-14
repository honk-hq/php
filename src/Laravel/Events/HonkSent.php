<?php

declare(strict_types=1);

namespace Honk\Laravel\Events;

use Honk\Accepted;
use Honk\Message;

/** Honk accepted a message (202, possibly a duplicate). */
final class HonkSent
{
    /**
     * @param string $via send, defer, queue or notification
     */
    public function __construct(
        public readonly Message $message,
        public readonly Accepted $accepted,
        public readonly string $idempotencyKey,
        public readonly string $via,
    ) {
    }
}
