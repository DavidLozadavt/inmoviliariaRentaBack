<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Visor 3D: costado izquierdo, parte trasera y techo del edificio (texturas enderezadas desde fotos). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            if (! Schema::hasColumn('buildings', 'facade_left_path')) {
                $table->string('facade_left_path')->nullable()->after('facade_side_path');
                $table->string('facade_back_path')->nullable()->after('facade_left_path');
                $table->string('roof_path')->nullable()->after('facade_back_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            if (Schema::hasColumn('buildings', 'facade_left_path')) {
                $table->dropColumn(['facade_left_path', 'facade_back_path', 'roof_path']);
            }
        });
    }
};
