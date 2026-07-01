<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition()
    {
        // Obtener un contrato que no tenga pago registrado aún
        $contract = Contract::doesntHave('payments')->inRandomOrder()->first();

        if (! $contract) {
            throw new \Exception("No hay contratos disponibles sin pago para generar Payment.");
        }

        // Fecha de pago dentro del periodo del contrato
        $paymentDate = now()->subDays(rand(0, 30))->format('Y-m-d');

        // Estado lógico
        $statusOptions = ['paid', 'pending'];
        $status = reset($statusOptions); // o rand() dependiendo de la distribucion

        return [
            'payment_date' => $paymentDate,
            'amount' => $contract->deposit > 0 ? $contract->deposit : rand(800000, 2500000),

            'status' => $status,
            'payment_method' => collect([
                'Nequi',
                'Daviplata',
                'PSE',
                'Tarjeta de crédito',
                'Tarjeta débito',
                'Transferencia bancaria'
            ])->random(),

            // Simula recibo almacenado
            'receipt_path' => "payments/receipt_contract_{$contract->id}.pdf",

            'contract_id' => $contract->id
        ];
    }
}
