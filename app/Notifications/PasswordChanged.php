<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

// The email after a new password is chosen with "Forgot password?" (PasswordResetController), so the person would
// notice if it wasn't them.
class PasswordChanged extends Notification
{
    public function __construct(public readonly Carbon $changedAt) {}

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

        return (new MailMessage)
            ->subject("Your {$app} password was changed")
            ->greeting("Hello {$notifiable->Name},")
            ->line("The password for your {$app} account, {$notifiable->Username}, was changed on {$this->changedAt->format('d M Y, H:i')} (Malaysia time), with a link from \"Forgot password?\". Everywhere else it was logged in has been logged out.")
            ->line("If it was you, there's nothing more to do.")
            ->line("If it wasn't, ask an administrator to check your account straight away.")
            ->action('Log in', route('login'))
            ->salutation("{$app}");
    }
}
