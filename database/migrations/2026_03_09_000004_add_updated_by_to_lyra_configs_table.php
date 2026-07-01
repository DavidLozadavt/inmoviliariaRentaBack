<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lyra_configs', function (Blueprint $table) {
            $table->string('updated_by')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('lyra_configs', function (Blueprint $table) {
            $table->dropColumn('updated_by');
        });
    }
};
