<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            
            // Fechas del contrato
            $table->date('start_date');
            $table->date('end_date');
            
            // Estado del contrato
            $table->enum('status', ['pending', 'active', 'inactive', 'expired', 'rejected'])->default('pending');
            
            // Documento (términos del contrato en JSON)
            $table->text('document_path')->nullable();
            
            // Depósito/garantía
            $table->decimal('deposit', 10, 2)->default(0);
            
            // Validación por soporte
            $table->enum('validated_by_support', ['yes', 'no'])->default('no');
            $table->timestamp('support_validation_date')->nullable();
            
            // Aceptación por inquilino
            $table->enum('accepted_by_tenant', ['yes', 'no'])->default('no');
            $table->timestamp('tenant_acceptance_date')->nullable();
            
            // Relaciones
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('landlord_id'); // Dueño/arrendador
            $table->unsignedBigInteger('tenant_id');   // Inquilino/arrendatario
            
            // Foreign keys
            $table->foreign('property_id')
                ->references('id')
                ->on('properties')
                ->onDelete('cascade')
                ->onUpdate('cascade');
                
            $table->foreign('landlord_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
                
            $table->foreign('tenant_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
            
            $table->timestamps();
            
            // Índices
            $table->index(['status', 'tenant_id']);
            $table->index(['status', 'landlord_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};