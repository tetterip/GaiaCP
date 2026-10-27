<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteCacheTest extends TestCase
{
    /** `optimize` on deploy runs route:cache, which refuses two routes with one name; a stale cache stays behind then. */
    public function test_the_routes_can_be_cached(): void
    {
        $routes = Route::getRoutes()->toSymfonyRouteCollection();

        $this->assertGreaterThan(0, $routes->count());
    }
}
