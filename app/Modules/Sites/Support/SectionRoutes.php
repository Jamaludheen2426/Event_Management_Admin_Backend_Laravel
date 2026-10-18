<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use Illuminate\Support\Facades\Route;

/**
 * Route shorthand for the builders' sections. Each route names its section
 * in route defaults; the controller's ServesSections methods read them.
 *
 * Reads and writes are registered separately because, on the admin side,
 * reads are public and writes need a permission.
 */
final class SectionRoutes
{
    /**
     * GET path (list) and, with $show, GET path/{id}.
     *
     * @param  class-string  $controller
     */
    public static function reads(string $controller, string $path, string $key, bool $show = false): void
    {
        Route::get($path, [$controller, 'index'])->defaults('section', $key);
        if ($show) {
            Route::get("{$path}/{id}", [$controller, 'show'])->whereNumber('id')->defaults('section', $key);
        }
    }

    /**
     * The writes a section offers: create, update, delete, status (PATCH and
     * PUT, as v1), replace (bulk PUT of the whole list).
     *
     * @param  class-string  $controller
     * @param  list<string>  $actions
     * @param  list<string>  $middleware
     */
    public static function writes(string $controller, string $path, string $key, array $actions, array $middleware = []): void
    {
        foreach ($actions as $action) {
            $routes = match ($action) {
                'create' => [Route::post($path, [$controller, 'store'])],
                'update' => [Route::put("{$path}/{id}", [$controller, 'update'])->whereNumber('id')],
                'delete' => [Route::delete("{$path}/{id}", [$controller, 'destroy'])->whereNumber('id')],
                'status' => [
                    Route::patch("{$path}/{id}/status", [$controller, 'status'])->whereNumber('id'),
                    Route::put("{$path}/{id}/status", [$controller, 'status'])->whereNumber('id'),
                ],
                'replace' => [Route::put($path, [$controller, 'replace'])],
                default => [],
            };
            foreach ($routes as $route) {
                $route->defaults('section', $key)->middleware($middleware);
            }
        }
    }

    /**
     * GET / PUT of a single-row section ("Footer settings retrieved").
     *
     * @param  class-string  $controller
     * @param  list<string>  $middleware
     */
    public static function setting(string $controller, string $path, string $setting, string $label, bool $read, bool $write, array $middleware = []): void
    {
        if ($read) {
            Route::get($path, [$controller, 'setting'])->defaults('setting', $setting)->defaults('label', $label);
        }
        if ($write) {
            Route::put($path, [$controller, 'saveSetting'])->defaults('setting', $setting)->defaults('label', $label)->middleware($middleware);
        }
    }
}
