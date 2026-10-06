<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('LegacyRoutes', function (Blueprint $table) {
            $table->increments('Id');
            $table->string('Address');
            $table->string('Params')->nullable();
            $table->string('Controller');
            $table->string('Function');
            $table->string('Verb');
            $table->boolean('AdminOnly')->default(false);
        });
    }
};
