<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visor 3D: texturas de fachada (frente y costado) recortadas y enderezadas en el navegador
 * a partir de la foto del edificio. El modelo 3D las usa para verse como el edificio real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            if (! Schema::hasColumn('buildings', 'facade_front_path')) {
                $table->string('facade_front_path')->nullable()->after('preview_path');
                $table->string('facade_side_path')->nullable()->after('facade_front_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            if (Schema::hasColumn('buildings', 'facade_front_path')) {
                $table->dropColumn(['facade_front_path', 'facade_side_path']);
            }
        });
    }
};
