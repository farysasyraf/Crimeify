<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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
     * The password each account created this time was given, by email: shown once, then kept only as a hash.
     *
     * @var array<string, string>
     */
    public array $passwords = [];

    /**
     * Create each account with a password of its own, random and hard to guess, and show it once, to write down or
     * change on My profile. An account whose email already exists is left unchanged.
     */
    public function run(): void
    {
        foreach (self::Accounts as $email => $name) {
            if (User::where('Email', $email)->exists()) {
                $this->command?->warn("{$email} already exists, left unchanged.");

                continue;
            }

            // Letters and numbers only, so it's easy to type in once.
            $password = $this->passwords[$email] = Str::password(16, symbols: false);

            // The username comes from the email (User::freeUsername): admin, demo.
            $user = User::create(['Name' => $name, 'Email' => $email, 'Password' => $password]);

            $this->command?->info("{$email}  username: {$user->Username}  password: {$password}");
        }

        if ($this->passwords !== []) {
            $this->command?->warn('These passwords are shown only now. Write them down, or change them on My profile after logging in.');
        }
    }
}
