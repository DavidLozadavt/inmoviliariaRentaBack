<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;


class RouteServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
   public function boot(): void
    {
        $this->mapApiRoutes();
    }
    public function mapApiRoutes():void 
    {
        Route::prefix('v1')
        ->name('v1.')
        ->middleware('api')
        ->group(base_path('routes/api.php'));
    }
}
