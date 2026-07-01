<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Hacer lat y lng nullable para permitir creación sin ubicación
            $table->string('lat')->nullable()->change();
            $table->string('lng')->nullable()->change();

            $table->longText('image_url')->nullable()->change();


            // 2. Migrar registros existentes: si image_url no es JSON array, convertirlo
            $properties = DB::table('properties')->whereNotNull('image_url')->get();

            foreach ($properties as $property) {
                $imageUrl = $property->image_url;

                // Si ya es un JSON array válido, dejarlo tal cual
                $decoded = json_decode($imageUrl, true);
                if (is_array($decoded)) {
                    continue;
                }

                // Si es una string simple (URL o base64), convertir a array JSON
                if (!empty($imageUrl)) {
                    DB::table('properties')
                        ->where('id', $property->id)
                        ->update(['image_url' => json_encode([$imageUrl])]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Revertir a NOT NULL
            $table->string('lat')->nullable(false)->change();
            $table->string('lng')->nullable(false)->change();
            $table->text('image_url')->nullable()->change();
        });
    }
};
