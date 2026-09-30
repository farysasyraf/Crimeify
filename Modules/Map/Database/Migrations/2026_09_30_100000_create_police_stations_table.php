<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The police stations the Map page lists under the map, by state, with each one's address and phone number.
     * "php artisan map:import-stations" starts them from Modules/Map/Database/data/police-stations.csv; administrators
     * keep them up to date on the Police stations page. Stations are found by their region and police district's
     * names, not dbo.PoliceDistricts' Ids, as importing the crime data again replaces those rows.
     */
    public function up(): void
    {
        Schema::create('PoliceStations', function (Blueprint $table) {
            $table->increments('Id');
            // The ISO 3166-2 code of the state or federal territory it's listed under, like MY-01 for Johor.
            $table->string('Region', 5);
            $table->string('District', 80);
            $table->string('Name', 120);
            $table->string('Address', 300)->nullable();
            $table->string('Phone', 30)->nullable();
            $table->unique(['Region', 'Name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('PoliceStations');
    }
};
