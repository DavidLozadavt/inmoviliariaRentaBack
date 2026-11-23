<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Visor 3D: plano o vista aérea del conjunto, usado como suelo del modelo para ubicar las torres. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            if (! Schema::hasColumn('buildings', 'site_plan_path')) {
                $table->string('site_plan_path')->nullable()->after('facade_side_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            if (Schema::hasColumn('buildings', 'site_plan_path')) {
                $table->dropColumn('site_plan_path');
            }
        });
    }
};
