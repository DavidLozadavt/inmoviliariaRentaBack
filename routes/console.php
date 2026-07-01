<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\VerificationCodeService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Limpiar códigos de verificación expirados cada día a las 2:00 AM
Schedule::call(function () {
    app(VerificationCodeService::class)->cleanupExpiredCodes();
})->daily()->at('02:00');

// Limpiar usuarios no verificados después de 7 días (semanal)
Schedule::call(function () {
    \App\Models\User::where('verification_status', 'pending')
        ->where('created_at', '<', now()->subDays(7))
        ->delete();
})->weekly()->sundays()->at('03:00');
