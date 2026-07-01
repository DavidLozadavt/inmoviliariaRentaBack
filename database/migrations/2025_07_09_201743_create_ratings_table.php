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
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->enum('recipient_role', ['landlord', 'tenant']); // si aplica
            $table->tinyInteger('score'); // 1 a 5, por ejemplo
            $table->text('comment')->nullable();
            $table->date('date');

            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('user_id');

            // Un usuario solo puede calificar una vez un mismo contrato
            $table->unique(['contract_id', 'user_id']);

            $table->foreign('contract_id')
                ->references('id')
                ->on('contracts')
                ->onDelete('cascade')
                ->onUpdate('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade')
                ->onUpdate('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
