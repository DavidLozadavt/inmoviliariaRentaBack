<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rental_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('user_id'); // inquilino
            $table->unsignedBigInteger('owner_id'); // dueño

            // Fecha y hora solicitada
            $table->date('requested_date');
            $table->time('requested_time');

            // Contra-propuesta (si el dueño propone otra fecha)
            $table->date('counter_date')->nullable();
            $table->time('counter_time')->nullable();

            // Estados: pending, accepted, rejected, counter_proposed, visit_completed, contract_sent
            $table->enum('status', [
                'pending',
                'accepted',
                'rejected',
                'counter_proposed',
                'visit_completed',
                'contract_sent'
            ])->default('pending');

            // Para liberar el botón después de la visita
            $table->timestamp('visit_end_time')->nullable();

            $table->foreign('property_id')
                ->references('id')
                ->on('properties')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('owner_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_requests');
    }
};