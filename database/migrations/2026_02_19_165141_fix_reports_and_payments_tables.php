<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Fix the Reports Table
        Schema::table('reports', function (Blueprint $table) {
            // Drop the foreign key first, then the unique constraint, then restore the foreign key
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');

            // Add the correct columns
            $table->text('description')->after('type');
            $table->string('status')->default('pending')->after('description');
            
            $table->unsignedBigInteger('property_id')->nullable()->after('status');
            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
            
            $table->unsignedBigInteger('reported_user_id')->nullable()->after('property_id');
            $table->foreign('reported_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // 2. Fix legacy Payment statuses so the Dashboard metrics work
        DB::table('payments')->where('status', 'completed')->update(['status' => 'paid']);
    }

    public function down(): void
    {
        // Reversal logic if needed...
    }
};
