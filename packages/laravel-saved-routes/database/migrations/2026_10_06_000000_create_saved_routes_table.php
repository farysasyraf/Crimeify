<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table for the routes added on the admin page.
     */
    public function up(): void
    {
        Schema::create(config('saved-routes.table', 'saved_routes'), function (Blueprint $table) {
            $table->id();
            // The fixed part of the address, which is also the route's name. Parameters are kept apart, like id/kw.
            $table->string('path', 200);
            $table->string('parameters', 200)->nullable();
            // As the admin page names it, like ReportController or Setup\RoleController.
            $table->string('controller', 150);
            $table->string('action', 100);
            // GET, POST, PUT, PATCH, DELETE or ANY.
            $table->string('method', 10);
            // The keys of the roles that can open it; null opens it to everyone who is logged in.
            $table->json('roles')->nullable();
            $table->timestamps();
            $table->index('path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('saved-routes.table', 'saved_routes'));
    }
};
