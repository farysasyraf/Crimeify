<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Map page's crime figures and where each police district's pin goes. Both are filled by
     * "php artisan map:import-crime".
     */
    public function up(): void
    {
        // A police district's pin, from Modules/Map/Database/data/police-districts.csv.
        Schema::create('PoliceDistricts', function (Blueprint $table) {
            $table->increments('Id');
            $table->string('State', 50);
            $table->string('Name', 80);
            // The ISO 3166-2 code of the region the pin is in, like MY-01 for Johor.
            $table->string('Region', 5);
            $table->decimal('Latitude', 8, 5);
            $table->decimal('Longitude', 8, 5);
            $table->unique(['State', 'Name']);
        });

        // Crime by police district from data.gov.my, one row per district, crime type and year. District "All" is
        // a state's total, and state "Malaysia" the country's; type "all" is the category's total.
        Schema::create('CrimeStats', function (Blueprint $table) {
            $table->increments('Id');
            $table->string('State', 50);
            $table->string('District', 80);
            $table->string('Category', 20);
            $table->string('Type', 40);
            $table->smallInteger('Year');
            $table->integer('Crimes');
            $table->unique(['State', 'District', 'Category', 'Type', 'Year']);
            $table->index('Year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('CrimeStats');
        Schema::dropIfExists('PoliceDistricts');
    }
};
