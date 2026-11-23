<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visor 3D: foto del edificio que envía el cliente (cover), imagen de previsualización del modelo 3D
 * (preview, la toma el editor al guardar) y tipo de espacio + proyección de cada foto
 * (equirect = 360°, flat = foto normal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            if (! Schema::hasColumn('buildings', 'cover_path')) {
                $table->string('cover_path')->nullable()->after('config_version');
                $table->string('preview_path')->nullable()->after('cover_path');
            }
        });

        Schema::table('panoramas', function (Blueprint $table) {
            if (! Schema::hasColumn('panoramas', 'kind')) {
                $table->string('kind', 30)->nullable()->after('space');          // habitacion, bano, cocina...
                $table->string('projection', 10)->default('equirect')->after('kind');
            }
        });
    }

    public function down(): void
    {
        Schema::table('panoramas', function (Blueprint $table) {
            if (Schema::hasColumn('panoramas', 'kind')) {
                $table->dropColumn(['kind', 'projection']);
            }
        });
        Schema::table('buildings', function (Blueprint $table) {
            if (Schema::hasColumn('buildings', 'cover_path')) {
                $table->dropColumn(['cover_path', 'preview_path']);
            }
        });
    }
};
