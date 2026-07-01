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
        Schema::create('maintenances', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->date('request_date');
            $table->enum('status', ['pending', 'in_progress', 'finished'])->default('pending');
            $table->date('resolution_date')->nullable();
            $table->enum('validated_by_tenant', ['yes', 'no'])->default('no');

            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('user_id');

            $table->foreign('property_id')
                ->references('id')->on('properties')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade')->onUpdate('cascade');

            // un usuario no puede crear dos mantenimientos activos en la misma propiedad
            $table->unique(['property_id', 'user_id', 'status'], 'maintenance_unique_active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenances');
    }
};
