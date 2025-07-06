<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\ImportServiceInterface;
use App\Services\ImportService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ImportServiceInterface::class, ImportService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
