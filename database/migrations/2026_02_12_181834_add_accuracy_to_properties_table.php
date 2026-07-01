<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Agregar campo accuracy si no existe
            if (!Schema::hasColumn('properties', 'accuracy')) {
                $table->decimal('accuracy', 10, 2)->nullable()->after('lng');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'accuracy')) {
                $table->dropColumn('accuracy');
            }
        });
    }
};
