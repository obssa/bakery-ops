<?php

namespace Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;

/**
 * Every HTTP call a test makes runs in a fresh request scope, as it does in production (one PHP-FPM process per
 * request on the live host, Octane's flush on the VPS path; traps.md, Octane). Without this, the application instance a
 * test shares across its requests keeps the scoped bindings (CurrentBranch, ActorContext, ActingAsSession) and the
 * controllers the router has built, so what one request resolved (the person acting by short code, the device, the
 * branch) silently carries into the next one and a test can pass on state production never has.
 *
 * Around each call: the test's own scoped instances (what actInBranch / withActor set up for factories and direct
 * calls) are put aside, the request starts with none and with no cached controller, and afterwards the test's
 * instances are put back, so the code after a request sees the context the test set, never the request's.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @param  string  $method
     * @param  string  $uri
     * @param  array<mixed>  $parameters
     * @param  array<mixed>  $cookies
     * @param  array<mixed>  $files
     * @param  array<mixed>  $server
     * @param  string|null  $content
     * @return TestResponse
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $kept = $this->scopedInstances();
        $this->startFreshRequestScope();
        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->startFreshRequestScope();
            foreach ($kept as $abstract => $instance) {
                $this->app->instance($abstract, $instance);
            }
        }
    }

    /** No scoped instance and no controller built by an earlier request: what a new PHP process starts with. */
    private function startFreshRequestScope(): void
    {
        $this->app->forgetScopedInstances();
        foreach ($this->app->make(Router::class)->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $route->flushController();
        }
    }

    /**
     * The scoped bindings resolved so far in the test, abstract => instance.
     *
     * @return array<string, mixed>
     */
    private function scopedInstances(): array
    {
        return (function (): array {
            /** @var Container $this */
            $kept = [];
            foreach ($this->scopedInstances as $abstract) {
                if (is_string($abstract) && array_key_exists($abstract, $this->instances)) {
                    $kept[$abstract] = $this->instances[$abstract];
                }
            }

            return $kept;
        })->call($this->app);
    }
}
