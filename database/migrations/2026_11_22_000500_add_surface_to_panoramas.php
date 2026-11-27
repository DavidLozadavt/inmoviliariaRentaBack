<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visor 3D: un espacio se arma con varias fotos. Cada foto indica qué superficie muestra
 * (pared frontal/derecha/fondo/izquierda, piso, techo o detalle) para reconstruir la habitación en 3D.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panoramas', function (Blueprint $table) {
            if (! Schema::hasColumn('panoramas', 'surface')) {
                $table->string('surface', 12)->nullable()->after('projection');
            }
        });
    }

    public function down(): void
    {
        Schema::table('panoramas', function (Blueprint $table) {
            if (Schema::hasColumn('panoramas', 'surface')) {
                $table->dropColumn('surface');
            }
        });
    }
};
