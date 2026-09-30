<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When each user last opened a page and last logged out, for the users log on the Users page.
     */
    public function up(): void
    {
        Schema::table('Users', function (Blueprint $table) {
            if (! Schema::hasColumn('Users', 'LastSeenAt')) {
                $table->dateTime('LastSeenAt', 3)->nullable();
            }
            if (! Schema::hasColumn('Users', 'LoggedOutAt')) {
                $table->dateTime('LoggedOutAt', 3)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Users', function (Blueprint $table) {
            $table->dropColumn(['LastSeenAt', 'LoggedOutAt']);
        });
    }
};
