<?php

declare(strict_types=1);

namespace Honk;

/** One refused field, from the server (error.fields[]) or from the SDK's few local checks. */
final class FieldError
{
    /**
     * @param string $field wire name: message, event_type, Idempotency-Key, body, …
     * @param string $code  required, too_long, too_short, invalid_enum, invalid_format, out_of_range, not_allowed, invalid_utf8
     */
    public function __construct(
        public readonly string $field,
        public readonly string $code,
        public readonly ?string $message = null,
    ) {
    }

    /** "event_type invalid_enum: must be one of event, problem, recovery" */
    public function __toString(): string
    {
        return "{$this->field} {$this->code}" . ($this->message === null || $this->message === '' ? '' : ": {$this->message}");
    }
}
