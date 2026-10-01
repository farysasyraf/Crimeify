<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every police station changed, added or deleted on the Police stations page, by hand or from an uploaded Excel
     * file, so the page can list who did what and when, as the Crime data page does with dbo.CrimeDataEdits.
     */
    public function up(): void
    {
        Schema::create('PoliceStationEdits', function (Blueprint $table) {
            $table->increments('Id');
            // Who made the edit. The name is kept as it was, so it still shows after the user is deleted.
            $table->unsignedInteger('UserId')->nullable();
            $table->string('UserName', 100);
            // "changed", "added" or "deleted", and whether it was made on the page or by uploading a file.
            $table->string('Action', 10);
            $table->string('Source', 10);
            // The station's Id, which stays the same when it's renamed. Not a foreign key: a deleted station's history
            // stays.
            $table->unsignedInteger('StationId');
            // Its details before and after, as JSON, like {"Region":"MY-01","District":"Muar","Name":"IPD Muar",
            // "Address":null,"Phone":"06-952 1222"}: none before an addition, none after a deletion.
            $table->json('OldDetails')->nullable();
            $table->json('NewDetails')->nullable();
            $table->dateTime('CreatedAt');
            $table->index('CreatedAt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('PoliceStationEdits');
    }
};
