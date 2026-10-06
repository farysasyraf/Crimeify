<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Model events stay on (no WithoutModelEvents): a new user gets their username
     * and CreatedAt from them, and dbo.Users requires a username.
     */
    public function run(): void
    {
        $this->call(LoginUserSeeder::class);
    }
}
