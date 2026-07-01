<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_places', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // mall, park, university, hospital, terminal, cemetery, church, stadium
            $table->string('city');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->timestamps();

            $table->index(['city', 'type']);
            $table->index(['lat', 'lng']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_places');
    }
};
