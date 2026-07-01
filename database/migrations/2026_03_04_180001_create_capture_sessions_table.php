<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capture_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('capture_token')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('source', ['qr_mobile', 'direct_mobile', 'upload'])->default('upload');
            $table->enum('status', ['waiting', 'recording', 'uploading', 'completed', 'expired'])->default('waiting');
            $table->string('video_s3_url', 512)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_sessions');
    }
};
