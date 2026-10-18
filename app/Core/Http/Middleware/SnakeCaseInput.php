<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Converts request body keys from camelCase to snake_case, recursively, with
 * exactly the rule the Node API's bodyTransform used: every capital letter
 * becomes "_" + lowercase ("userId" becomes "user_id", "OTP" becomes "_o_t_p").
 *
 * Only JSON and urlencoded bodies are converted. Multipart (file upload) bodies
 * were never converted by Node either, because multer parsed them after the
 * transform had already run, and some upload screens depend on that.
 */
final class SnakeCaseInput
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isJson()) {
            $request->json()->replace((array) self::transform($request->json()->all()));
        } elseif (str_contains((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            $request->request->replace((array) self::transform($request->request->all()));
        }

        return $next($request);
    }

    public static function transform(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[is_string($key) ? self::snake($key) : $key] = self::transform($item);
        }

        return $out;
    }

    public static function snake(string $key): string
    {
        return preg_replace_callback('/[A-Z]/', static fn (array $m): string => '_'.strtolower($m[0]), $key) ?? $key;
    }
}
