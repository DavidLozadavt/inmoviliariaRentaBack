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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->string('address');
            $table->string('city');
            $table->string('status');

            // Estado de aprobación por el admin
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])
                  ->default('pending')
                  ->comment('Estado de aprobación: pending (pendiente), approved (aprobada), rejected (rechazada)');

            // Visibilidad de la publicación
            $table->enum('visibility', ['published', 'hidden'])
                  ->default('published')
                  ->comment('Visibilidad: published (publicada), hidden (oculta)');

            $table->decimal('monthly_price', 12, 2); // hasta 9,999,999,999.99
            $table->integer('area_m2');
            $table->string('num_bedrooms');
            $table->string('num_bathrooms');
            $table->string('included_services');
            $table->date('publication_date');
            $table->text('image_url');
            $table->string('lat');
            $table->string('lng');
            $table->decimal('accuracy', 10, 2)->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('user_id');

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');

            $table->timestamps();

            // Índices para mejorar rendimiento de consultas
            $table->index('approval_status');
            $table->index('visibility');
            $table->index(['approval_status', 'visibility']); // Índice compuesto
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
