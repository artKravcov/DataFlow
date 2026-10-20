<?php

namespace App\Providers;

use App\Graph\AssetResolver;
use App\Graph\UnavailableAssetResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AssetResolver::class, UnavailableAssetResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->bind(AssetResolver::class, UnavailableAssetResolver::class);
    }
}
