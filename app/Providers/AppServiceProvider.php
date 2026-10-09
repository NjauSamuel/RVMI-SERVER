<?php

namespace App\Providers;

use App\Services\Auth\GoogleApiClientIdTokenVerifier;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GoogleIdTokenVerifier::class, GoogleApiClientIdTokenVerifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
