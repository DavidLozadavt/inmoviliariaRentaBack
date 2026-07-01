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
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('code', 6); // Código OTP de 6 dígitos
            $table->string('token')->unique(); // Token único para links
            $table->enum('type', ['email_verification', 'password_reset']); // Tipo de código
            $table->timestamp('expires_at'); // Fecha de expiración
            $table->boolean('used')->default(false); // Marca si ya fue usado
            $table->timestamp('last_sent_at')->nullable(); // Para controlar cooldown
            $table->timestamps();

            // Índices para mejorar rendimiento
            $table->index(['email', 'type']);
            $table->index(['token']);
            $table->index(['expires_at']);
        });

        // Modificar tabla users para agregar estado de verificación
        Schema::table('users', function (Blueprint $table) {
            $table->enum('verification_status', ['pending', 'verified'])->default('pending')->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('verification_status');
        });
    }
};
