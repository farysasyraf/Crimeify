<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each region's population by year, which the map divides crime by to shade it per 100,000 people. Filled by
     * "php artisan map:import-population".
     */
    public function up(): void
    {
        Schema::create('Populations', function (Blueprint $table) {
            $table->increments('Id');
            // The ISO 3166-2 code of the state or federal territory, like MY-01 for Johor.
            $table->string('Region', 5);
            $table->smallInteger('Year');
            $table->integer('People');
            $table->unique(['Region', 'Year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Populations');
    }
};
