<?php

declare(strict_types=1);

namespace Honk\Exception;

/** 401/403: invalid or revoked key, project or workspace suspended. */
class AuthException extends HonkException
{
}
