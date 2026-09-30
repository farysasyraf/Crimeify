<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class LoginUserSeeder extends Seeder
{
    /**
     * Accounts that can log in, keyed by email.
     *
     * @var array<string, string>
     */
    private const Accounts = [
        'admin@myapp.local' => 'Administrator',
        'demo@myapp.local' => 'Demo User',
    ];

    /**
     * Password given to each account this seeder creates.
     */
    public const DefaultPassword = 'secret';

    /**
     * Create each account with the default password.
     * An account whose email already exists is left unchanged.
     */
    public function run(): void
    {
        foreach (self::Accounts as $email => $name) {
            if (User::where('Email', $email)->exists()) {
                $this->command?->warn("{$email} already exists, left unchanged.");

                continue;
            }

            // The username comes from the email (User::freeUsername): admin, demo.
            $user = User::create(['Name' => $name, 'Email' => $email, 'Password' => self::DefaultPassword]);

            $this->command?->info("{$email}  username: {$user->Username}  password: ".self::DefaultPassword);
        }
    }
}
