<?php

declare(strict_types=1);

namespace Honk;

use DateTimeImmutable;

/** The 202 answer: the message is durably stored (which does not mean a push was delivered). */
final class Accepted
{
    /**
     * @param string            $id         message id (msg_…); the original id when $duplicate is true
     * @param bool              $duplicate  this idempotency key was already accepted with the same payload (24 h)
     * @param DateTimeImmutable $receivedAt when the server accepted it (the first time, for a duplicate)
     * @param list<Warning>     $warnings   what the server corrected before accepting it ([] when nothing)
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $duplicate,
        public readonly DateTimeImmutable $receivedAt,
        public readonly array $warnings = [],
    ) {
    }

    /** True when the server corrected something (see $warnings). */
    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /** @return array{id: string, duplicate: bool, receivedAt: string, warnings: list<array{field: string, code: string, message: string}>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'duplicate' => $this->duplicate,
            'receivedAt' => $this->receivedAt->format('Y-m-d\TH:i:s.v\Z'),
            'warnings' => array_map(static fn (Warning $w) => $w->toArray(), $this->warnings),
        ];
    }
}
