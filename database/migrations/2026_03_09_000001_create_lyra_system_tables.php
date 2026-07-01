<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lyra_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->text('changelog')->nullable();
            $table->json('metrics')->nullable();
            $table->string('deployed_by')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamp('deployed_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lyra_configs', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->timestamps();
        });

        Schema::create('lyra_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('city')->nullable();
            $table->string('last_intent')->nullable();
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('lyra_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lyra_session_id')->constrained('lyra_sessions')->onDelete('cascade');
            $table->enum('role', ['user', 'assistant']);
            $table->text('content');
            $table->string('intent')->nullable();
            $table->float('confidence')->nullable();
            $table->json('entities')->nullable();
            $table->json('thinking_process')->nullable();
            $table->integer('processing_time_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lyra_messages');
        Schema::dropIfExists('lyra_sessions');
        Schema::dropIfExists('lyra_configs');
        Schema::dropIfExists('lyra_versions');
    }
};
