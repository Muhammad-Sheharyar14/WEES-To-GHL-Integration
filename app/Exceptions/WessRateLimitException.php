<?php

namespace App\Exceptions;

use Exception;

class WessRateLimitException extends Exception
{
    protected int $retryAfter;

    public function __construct(string $message = "WESS API rate limit exceeded (60 requests per minute).", int $retryAfter = 60, int $code = 429, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->retryAfter = $retryAfter;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
