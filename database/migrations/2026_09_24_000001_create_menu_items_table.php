<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create dbo.MenuItems for the left navigation menu, starting with the links the top bar used to have.
     */
    public function up(): void
    {
        Schema::create('MenuItems', function (Blueprint $table) {
            $table->increments('Id');
            $table->string('Label', 100);
            $table->string('Url', 255);
            $table->integer('SortOrder')->default(0);
            $table->dateTime('CreatedAt')->nullable()->useCurrent();
        });

        DB::table('MenuItems')->insert([
            ['Label' => 'Users', 'Url' => '/', 'SortOrder' => 1],
            ['Label' => 'Add user', 'Url' => '/users/create', 'SortOrder' => 2],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('MenuItems');
    }
};
