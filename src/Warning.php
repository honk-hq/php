<?php

declare(strict_types=1);

namespace Honk;

/**
 * Something the server corrected in an accepted message (202 `warnings`): the message was
 * delivered, but a field was shortened, rewritten or left out. Warnings are never errors.
 */
final readonly class Warning
{
    public const TRUNCATED = 'truncated';   // shortened to the limit ("…" for text)
    public const NORMALIZED = 'normalized'; // rewritten into an accepted form (one line, control characters, type)
    public const IGNORED = 'ignored';       // an invalid value left out; the default applies
    public const CLAMPED = 'clamped';       // a number moved into its range
    public const DOWNGRADED = 'downgraded'; // a priority the key may not send, lowered
    public const HASHED = 'hashed';         // a long group key shortened with a hash

    /**
     * @param string $field   wire name: title, metadata.order, actions[1].url, …
     * @param string $code    truncated, normalized, ignored, clamped, downgraded or hashed (newer servers may add codes)
     * @param string $message English, for logs: what was wrong and what was done
     */
    public function __construct(
        public string $field,
        public string $code,
        public string $message = '',
    ) {
    }

    /**
     * The warnings of a decoded 202 body; anything malformed is skipped.
     *
     * @return list<self>
     */
    public static function listFrom(mixed $warnings): array
    {
        $out = [];
        foreach (is_array($warnings) ? $warnings : [] as $w) {
            if (is_array($w) && is_string($w['field'] ?? null) && is_string($w['code'] ?? null)) {
                $out[] = new self($w['field'], $w['code'], is_string($w['message'] ?? null) ? $w['message'] : '');
            }
        }

        return $out;
    }

    /** @return array{field: string, code: string, message: string} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'code' => $this->code, 'message' => $this->message];
    }

    /** "title truncated: shortened to 160 characters" */
    public function __toString(): string
    {
        return "{$this->field} {$this->code}" . ($this->message === '' ? '' : ": {$this->message}");
    }
}
