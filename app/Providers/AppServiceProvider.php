<?php

namespace App\Providers;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the default session guard to the StatefulGuard contract so
        // our action classes can type-hint it and receive the current guard.
        $this->app->bind(StatefulGuard::class, function ($app) {
            return $app['auth']->guard();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Return JSON resources without the default top-level "data" wrapper
        // so frontend can read properties directly (e.g., profile.balance).
        JsonResource::withoutWrapping();
    }
}
