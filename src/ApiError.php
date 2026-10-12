<?php

namespace studionoir\watchdog;

/** An error the API reports to the caller as-is: HTTP status plus a Dutch message. */
class ApiError extends \RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}
