<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'paid' to the enum statuses. 
        // Note: Using raw SQL because Laravel's enum update is tricky with MySQL.
        if (DB::getDriverName() !== 'mysql') {
            return; // SQLite (tests) no soporta MODIFY ni ENUM
        }
        DB::statement("ALTER TABLE contracts MODIFY COLUMN status ENUM('pending', 'active', 'inactive', 'expired', 'rejected', 'paid') DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // SQLite (tests) no soporta MODIFY ni ENUM
        }
        DB::statement("ALTER TABLE contracts MODIFY COLUMN status ENUM('pending', 'active', 'inactive', 'expired', 'rejected') DEFAULT 'pending'");
    }
};
