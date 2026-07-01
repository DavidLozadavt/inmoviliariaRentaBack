<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\ActivityService;

class PaymentObserver
{
    public function created(Payment $payment): void
    {
        if ($payment->status === 'paid') {
            $tenant = $payment->contract?->tenant;
            ActivityService::logPaymentReceived($payment, $tenant);
        }
    }

    public function updated(Payment $payment): void
    {
        if ($payment->isDirty('status') && $payment->status === 'paid') {
            $tenant = $payment->contract?->tenant;
            ActivityService::logPaymentReceived($payment, $tenant);
        }
    }
}
