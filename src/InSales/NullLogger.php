<?php

declare(strict_types=1);

namespace InSales;

use Psr\Log\AbstractLogger;

/**
 * Null logger that discards all log messages.
 * Used as default when no logger is provided.
 */
final class NullLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        // Discard all log messages
    }
}
