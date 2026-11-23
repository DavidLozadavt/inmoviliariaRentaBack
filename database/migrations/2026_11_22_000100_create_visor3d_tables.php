<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visor 3D de edificios (Fase 1).
 * La unidad 3D ES la Property: solo se agregan columnas de ubicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('buildings')) {
            Schema::create('buildings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // dueño (landlord)
                $table->string('name', 150);
                $table->string('address')->nullable();
                $table->string('city', 100)->nullable();
                $table->json('config');                       // torres, pisos, layout, colores, pisos especiales
                $table->unsignedInteger('config_version')->default(1); // llave de caché
                $table->string('public_token', 64)->nullable()->unique(); // Fase 5
                $table->timestamp('public_expires_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('unit_types')) {
            Schema::create('unit_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('building_id')->constrained()->cascadeOnDelete();
                $table->string('code', 10);                   // A, B, C...
                $table->string('name', 100)->nullable();
                $table->decimal('area_m2', 8, 2)->nullable();
                $table->unsignedTinyInteger('bedrooms')->nullable();
                $table->unsignedTinyInteger('bathrooms')->nullable();
                $table->string('color', 9)->nullable();
                $table->timestamps();
                $table->unique(['building_id', 'code']);
            });
        }

        Schema::table('properties', function (Blueprint $table) {
            if (! Schema::hasColumn('properties', 'building_id')) {
                $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('unit_type_id')->nullable()->constrained()->nullOnDelete();
                $table->string('tower', 20)->nullable();
                $table->smallInteger('floor')->nullable();
                $table->string('unit_code', 20)->nullable();
                // available | reserved | rented | sold. Null = se deduce de status.
                $table->string('commercial_status', 20)->nullable();
                $table->unique(['building_id', 'tower', 'unit_code'], 'properties_building_unit_unique');
            }
        });

        // Fase 4 (se crean ya para no tocar el esquema dos veces)
        if (! Schema::hasTable('panoramas')) {
            Schema::create('panoramas', function (Blueprint $table) {
                $table->id();
                $table->morphs('owner');                      // UnitType o Property
                $table->string('space', 60);                  // sala, cocina, habitación 1...
                $table->string('path');                       // webp en disco public
                $table->string('thumb_path')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('size_kb')->nullable();
                $table->boolean('is_start')->default(false);
                $table->unsignedSmallInteger('order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('panorama_hotspots')) {
            Schema::create('panorama_hotspots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('panorama_id')->constrained()->cascadeOnDelete();
                $table->foreignId('target_panorama_id')->constrained('panoramas')->cascadeOnDelete();
                $table->decimal('yaw', 8, 5);
                $table->decimal('pitch', 8, 5);
                $table->string('label', 60)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('panorama_hotspots');
        Schema::dropIfExists('panoramas');
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'building_id')) {
                $table->dropUnique('properties_building_unit_unique');
                $table->dropConstrainedForeignId('building_id');
                $table->dropConstrainedForeignId('unit_type_id');
                $table->dropColumn(['tower', 'floor', 'unit_code', 'commercial_status']);
            }
        });
        Schema::dropIfExists('unit_types');
        Schema::dropIfExists('buildings');
    }
};
