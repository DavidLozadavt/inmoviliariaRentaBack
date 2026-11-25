<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Edificios que llegan del ERP (NexiLiving Business): referencia del proyecto de origen. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('buildings', 'erp_ref')) {
            Schema::table('buildings', function (Blueprint $t) {
                $t->string('erp_ref', 80)->nullable()->unique()->after('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('buildings', fn (Blueprint $t) => $t->dropColumn('erp_ref'));
    }
};
