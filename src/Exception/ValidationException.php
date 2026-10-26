<?php

declare(strict_types=1);

namespace Honk\Exception;

use Honk\FieldError;
use Throwable;

/**
 * The server refused the request (400, 413, 415, 422: e.g. no message and no title, an unknown
 * field, an invalid event_type), or the SDK did before sending (no message and no title, an
 * invalid Idempotency-Key). $fields lists each refused field with its code and message.
 */
class ValidationException extends HonkException
{
    /**
     * @param list<FieldError> $fields every refused field (field path, code, message)
     * @param bool             $local  true when rejected before any request was made
     */
    public function __construct(
        string $message,
        public readonly array $fields = [],
        public readonly bool $local = false,
        ?int $status = null,
        ?string $errorCode = 'validation_failed',
        ?string $requestId = null,
        ?string $idempotencyKey = null,
        int $attempts = 0,
        mixed $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $requestId, $idempotencyKey, $attempts, null, $body, $previous);
    }

    /**
     * @param list<FieldError> $fields
     */
    public static function local(array $fields): self
    {
        return new self('Invalid Honk message: ' . implode('; ', array_map('strval', $fields)), $fields, true);
    }
}
