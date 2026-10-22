<?php

declare(strict_types=1);

namespace InSales;

/**
 * Exception for InSales API errors.
 *
 * Carries HTTP status, usage-limit header, retry-after header,
 * and distinguishes transient errors (429, 5xx, connection issues).
 */
final class ApiException extends \RuntimeException
{
    public readonly int $status;
    public readonly ?string $usage;
    public readonly ?string $retryAfter;
    private readonly bool $isTransient;

    public function __construct(
        int $status,
        string $message = '',
        ?string $usage = null,
        ?string $retryAfter = null,
        bool $isTransient = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->usage = $usage;
        $this->retryAfter = $retryAfter;
        $this->isTransient = $isTransient;
    }

    /**
     * Whether this error is transient and may succeed on retry.
     */
    public function isTemporary(): bool
    {
        return $this->isTransient;
    }
}