<?php

declare(strict_types=1);

namespace Honk;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Honk\Exception\ValidationException;

/**
 * Turns a Message into the JSON body of POST /v1/messages. The server is the authority on field
 * contents: it corrects what it can and reports it as warnings (contracts/API.md §5.1), so the
 * only local check is the one it would refuse anyway: a message or a title.
 *
 * @internal
 */
final class Wire
{
    /**
     * @param array{source?: ?string, environment?: ?string, channel?: ?string} $defaults
     *
     * @return array<string, mixed> wire fields (snake_case)
     *
     * @throws ValidationException when there is neither a message nor a title
     */
    public static function build(Message $message, array $defaults = []): array
    {
        $body = [];
        foreach (Message::FIELDS as $field => $wire) {
            $value = $message->{$field};
            if (($value === null || $value === '') && in_array($field, ['source', 'environment', 'channel'], true)) {
                $value = $defaults[$field] ?? null;
            }
            if ($value === null || $value === '' || ($field === 'actions' && $value === [])) {
                continue;
            }
            if ($value instanceof DateTimeInterface) {
                $value = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
            }
            // Horn names (any case) become the canonical value, so the idempotency payload is
            // canonical; anything else is sent as given and the server decides.
            if ($value instanceof Severity) {
                $value = $value->value;
            } elseif ($field === 'severity' && is_string($value) && ($parsed = Severity::tryParse($value)) !== null) {
                $value = $parsed->value;
            }
            $body[$wire] = $value;
        }

        if (self::blank($body['message'] ?? null) && self::blank($body['title'] ?? null)) {
            throw ValidationException::local([new FieldError('message', 'required', 'a message or a title is required')]);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws ValidationException when the body cannot be encoded as JSON (invalid UTF-8, NAN, …)
     */
    public static function encode(array $body): string
    {
        if (isset($body['metadata']) && is_array($body['metadata'])) {
            $body['metadata'] = (object) $body['metadata'];
        }
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw ValidationException::local([new FieldError('body', 'invalid_format', 'cannot be encoded as JSON: ' . json_last_error_msg())]);
        }

        return $json;
    }

    /**
     * Checks an Idempotency-Key: 1–128 printable ASCII characters (0x21–0x7E). Kept locally
     * because anything else cannot be sent safely as an HTTP header.
     */
    public static function checkIdempotencyKey(string $key): string
    {
        if (preg_match('/^[\x21-\x7E]{1,128}$/', $key) !== 1) {
            throw ValidationException::local([new FieldError('Idempotency-Key', 'invalid_format', 'use 1-128 printable ASCII characters without spaces, e.g. "request-4812"')]);
        }

        return $key;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
