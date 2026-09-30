<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every crime figure changed, added or deleted on the Crime data page since the last import from data.gov.my,
     * so the page can list what changed and the import can stop rather than replace the edits.
     */
    public function up(): void
    {
        Schema::create('CrimeDataEdits', function (Blueprint $table) {
            $table->increments('Id');
            // Who made the edit. The name is kept as it was, so it still shows after the user is deleted.
            $table->unsignedInteger('UserId')->nullable();
            $table->string('UserName', 100);
            // "changed", "added" or "deleted", and whether it was made on the page or by uploading a file.
            $table->string('Action', 10);
            $table->string('Source', 10);
            $table->string('State', 50);
            $table->string('District', 80);
            $table->string('Category', 20);
            $table->string('Type', 40);
            $table->smallInteger('Year');
            // The count before and after: none before an addition, none after a deletion.
            $table->integer('OldCrimes')->nullable();
            $table->integer('NewCrimes')->nullable();
            $table->dateTime('CreatedAt');
            $table->index('CreatedAt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('CrimeDataEdits');
    }
};
