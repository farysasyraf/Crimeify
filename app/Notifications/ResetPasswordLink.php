<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// The email "Forgot password?" sends (PasswordResetController): a link to choose a new password, which works once,
// for config('auth.passwords.users.expire') minutes. It names the account, so the person can tell it's theirs.
class ResetPasswordLink extends Notification
{
    public function __construct(public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $app = config('app.name');
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject("Choose a new password for {$app}")
            ->greeting("Hello {$notifiable->Name},")
            ->line("Someone asked for a link to choose a new password for your {$app} account, {$notifiable->Username} ({$notifiable->Email}).")
            ->action('Choose a new password', route('password.reset', ['token' => $this->token, 'email' => $notifiable->Email]))
            ->line("The link works once, for the next {$minutes} minutes.")
            ->line("If you didn't ask for it, ignore this email: your password stays as it is, and no one can use the link without this email.")
            ->salutation("{$app}");
    }
}
