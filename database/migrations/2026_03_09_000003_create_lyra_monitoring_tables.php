<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MODIFIED: Adding properties_found to lyra_messages
        Schema::table('lyra_messages', function (Blueprint $table) {
            $table->integer('properties_found')->nullable()->after('processing_time_ms');
        });

        // Table for service incidents
        Schema::create('lyra_service_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('service'); // cache, queue, database, geo_engine, nlp_engine
            $table->string('status'); // degraded, down, resolved
            $table->integer('latency_ms')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // Table for admin alerts
        Schema::create('lyra_admin_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // high_out_of_context, system_error, security
            $table->string('message');
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lyra_admin_alerts');
        Schema::dropIfExists('lyra_service_incidents');
        Schema::table('lyra_messages', function (Blueprint $table) {
            $table->dropColumn('properties_found');
        });
    }
};
