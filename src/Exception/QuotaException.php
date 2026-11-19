<?php

declare(strict_types=1);

namespace Honk\Exception;

/**
 * 429 after retries: quota_exceeded (the workspace's daily messages_per_day, until UTC midnight),
 * rate_limited or overloaded (server backpressure). $retryAfter says when to try again.
 */
class QuotaException extends HonkException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
