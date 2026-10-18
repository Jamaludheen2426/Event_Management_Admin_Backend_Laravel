<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use App\Core\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The single exception map. Every error leaves the API in the v1 envelope
 * { success: false, message, errors?, timestamp } with the status code the Node
 * API used for the same situation.
 *
 * Laravel converts ModelNotFound and Authorization exceptions into HTTP
 * exceptions before these callbacks run, so both forms are listed.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(static fn (): bool => true);

        $exceptions->render(static fn (ApiException $e): JsonResponse => ApiResponse::error($e->getMessage(), $e->status(), $e->errors()));

        $exceptions->render(static fn (ValidationException $e): JsonResponse => ApiResponse::error(
            (string) $e->validator->errors()->first(),
            400,
            $e->errors(),
        ));

        $exceptions->render(static function (AuthenticationException $e, Request $request): JsonResponse {
            $message = 'Authentication required. Please login.';
            foreach ((array) config('auth.unauthenticated_messages') as $prefix => $text) {
                if (str_starts_with($request->path(), (string) $prefix)) {
                    $message = (string) $text;
                    break;
                }
            }

            return ApiResponse::error($message, 401);
        });

        $exceptions->render(static fn (AuthorizationException|AccessDeniedHttpException $e): JsonResponse => ApiResponse::error(
            'Access denied. Insufficient permissions.',
            403,
        ));

        $exceptions->render(static fn (ModelNotFoundException|NotFoundHttpException $e): JsonResponse => ApiResponse::error('Resource not found', 404));

        $exceptions->render(static fn (ThrottleRequestsException $e): JsonResponse => ApiResponse::error(
            'Too many requests. Please wait a moment and try again.',
            429,
        ));

        $exceptions->render(static fn (UniqueConstraintViolationException $e): JsonResponse => ApiResponse::error('Duplicate entry', 400));

        $exceptions->render(static fn (QueryException $e): JsonResponse => self::query($e));

        $exceptions->render(static function (Throwable $e) {
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                return ApiResponse::error($e->getMessage() !== '' ? $e->getMessage() : self::defaultMessage($status), $status);
            }

            return ApiResponse::error(app()->isProduction() ? 'Internal server error' : $e->getMessage(), 500);
        });
    }

    /** The v1 wording for an HTTP error raised without a message (abort(403), …). */
    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            401 => 'Authentication required. Please login.',
            403 => 'Access denied. Insufficient permissions.',
            404 => 'Resource not found',
            405 => 'Method not allowed',
            429 => 'Too many requests. Please wait a moment and try again.',
            503 => 'This is not available yet',
            default => 'Request failed',
        };
    }

    /**
     * Database errors never leak SQL. Foreign key failures and bad values answer
     * 400 with the same words the Node API used.
     */
    private static function query(QueryException $e): JsonResponse
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        if (in_array($driverCode, [1451, 1452], true)) {
            return ApiResponse::error('Related record not found', 400);
        }

        report($e);

        return ApiResponse::error('Some values could not be saved. Please review the form and try again.', 400);
    }
}
