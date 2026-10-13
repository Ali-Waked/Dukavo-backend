<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A requested change conflicts with the current state of the domain.
 *
 * These are expected refusals, not defects: cancelling a shipped order,
 * editing a delivered one, removing the default store. They render as `409`
 * with the standard error envelope so the frontend can show the reason verbatim,
 * and they carry structured context for the log instead of a bare message.
 */
class BusinessRuleViolation extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, private readonly array $context = [], private readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function because(string $message, array $context = [], ?string $errorCode = null): self
    {
        return new self($message, $context, $errorCode);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Structured data merged into the exception's log context.
     *
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return $this->context + ['error_code' => $this->errorCode];
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], 409);
    }
}
