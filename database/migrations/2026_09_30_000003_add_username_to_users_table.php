<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A username for each user, to log in with instead of the email. Users already there get one from their email,
     * as User::freeUsername makes them: the part before the @, as far as a username allows, with a number after it if
     * another user has it already (ada@example.com → ada, then ada2). Then every user must have one, and no two the same.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('Users', 'Username')) {
            Schema::table('Users', function (Blueprint $table) {
                $table->string('Username', 30)->nullable();
            });
        }

        $taken = DB::table('Users')->whereNotNull('Username')->pluck('Username')->map(fn (string $name) => Str::lower($name))->flip()->all();

        foreach (DB::table('Users')->whereNull('Username')->orderBy('Id')->get(['Id', 'Email']) as $user) {
            $base = Str::of(Str::before($user->Email, '@'))->ascii()->lower()->replaceMatches('/[^a-z0-9._-]/', '')->ltrim('._-')->substr(0, 30)->value();
            $base = strlen($base) >= 3 ? $base : 'user'.$base;
            $username = $base;

            for ($n = 2; isset($taken[$username]); $n++) {
                $username = substr($base, 0, 30 - strlen((string) $n)).$n;
            }

            $taken[$username] = true;
            DB::table('Users')->where('Id', $user->Id)->update(['Username' => $username]);
        }

        Schema::table('Users', function (Blueprint $table) {
            $table->string('Username', 30)->nullable(false)->change();
            $table->unique('Username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Users', function (Blueprint $table) {
            $table->dropUnique(['Username']);
            $table->dropColumn('Username');
        });
    }
};
