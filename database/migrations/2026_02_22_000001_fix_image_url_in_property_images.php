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
        if (Schema::hasTable('property_images')) {
            Schema::table('property_images', function (Blueprint $table) {
                // Make image_url nullable if it exists to avoid "no default value" errors
                if (Schema::hasColumn('property_images', 'image_url')) {
                    $table->string('image_url')->nullable()->change();
                }
                
                // Ensure other columns are also safe
                if (Schema::hasColumn('property_images', 'path')) {
                    $table->string('path')->nullable()->change();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
