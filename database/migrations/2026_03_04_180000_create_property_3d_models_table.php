<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_3d_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'uploading', 'processing', 'ready', 'failed', 'expired'])->default('pending');
            $table->string('video_s3_url', 512)->nullable();
            $table->string('model_s3_url', 512)->nullable();
            $table->string('luma_task_id', 255)->nullable()->index();
            $table->decimal('file_size_mb', 8, 2)->nullable();
            $table->string('format', 20)->nullable();
            $table->json('dimensions')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique('property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_3d_models');
    }
};
