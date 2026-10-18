<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /** No test ever reaches a real service (MSG91, FCM, providers): unfaked HTTP calls fail. */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * Every test request starts unauthenticated, like a real HTTP request.
     * Laravel's test client otherwise keeps the user resolved by an earlier
     * request of the same test, so a second request with another user's token
     * would silently run as the first user.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
