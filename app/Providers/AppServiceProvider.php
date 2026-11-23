<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

use App\Models\User;
use App\Models\Property;
use App\Models\Contract;
use App\Models\Payment;

use App\Observers\UserObserver;
use App\Observers\PropertyObserver;
use App\Observers\ContractObserver;
use App\Observers\PaymentObserver;
use App\Models\LyraMessage;
use App\Observers\LyraMessageObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
        $this->app->singleton(LyraVoicePersonality::class, function () {
            return new LyraVoicePersonality();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);
        Property::observe(PropertyObserver::class);
        Contract::observe(ContractObserver::class);
        Payment::observe(PaymentObserver::class);
        LyraMessage::observe(LyraMessageObserver::class);

        // Visor 3D: {unit} en las rutas de buildings es una Property
        Route::model('unit', Property::class);

        // Visor 3D: invalida la caché del edificio al cambiar unidades, contratos, pagos o PQRS
        foreach ([Property::class, Contract::class, Payment::class, \App\Models\Maintenance::class] as $model) {
            $model::observe(\App\Observers\Visor3dCacheObserver::class);
        }
    }
}
