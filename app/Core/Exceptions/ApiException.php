<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * A business rule said no. The message is written for the person using the
 * screen ("That event category is not included in your subscription plan.")
 * and is shown as-is, with the status code chosen here.
 *
 * The named constructors mirror the Node API's ApiError helpers so a ported rule
 * keeps its status code.
 *
 * Subclasses (PlanRequired, PlanLimitReached, ...) keep this constructor, so the
 * named constructors below return the subclass.
 *
 * @phpstan-consistent-constructor
 */
class ApiException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 400, private readonly mixed $errors = null)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errors(): mixed
    {
        return $this->errors;
    }

    public static function badRequest(string $message = 'Bad request', mixed $errors = null): static
    {
        return new static($message, 400, $errors);
    }

    public static function unauthorized(string $message = 'Unauthorized'): static
    {
        return new static($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): static
    {
        return new static($message, 403);
    }

    public static function notFound(string $message = 'Resource not found'): static
    {
        return new static($message, 404);
    }

    public static function conflict(string $message = 'Conflict'): static
    {
        return new static($message, 409);
    }

    public static function unprocessable(string $message = 'Validation failed', mixed $errors = null): static
    {
        return new static($message, 422, $errors);
    }

    /** The request was fine; a capability it needs (payment provider, SMS) is not connected. */
    public static function serviceUnavailable(string $message = 'This is not available yet'): static
    {
        return new static($message, 503);
    }
}
