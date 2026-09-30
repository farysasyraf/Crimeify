<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Checks the app can send email with the MAIL_* settings in .env, like Gmail's for "Forgot password?": sends a short
// email to the address given, and says what went wrong if it can't.
Artisan::command('mail:test {to : The address to send the test email to}', function (string $to) {
    $mailer = config('mail.default');

    if (in_array($mailer, ['log', 'array'], true)) {
        $this->warn("MAIL_MAILER is {$mailer}, so emails are only written to the log, not sent. Set MAIL_MAILER=smtp to send them.");
    }

    try {
        Mail::raw('This is a test email from '.config('app.name').'. If you can read it, "Forgot password?" can send its links.', function ($message) use ($to) {
            $message->to($to)->subject(config('app.name').' can send email');
        });
    } catch (Throwable $problem) {
        $this->error("Couldn't send it: {$problem->getMessage()}");
        $this->line('For Gmail: MAIL_HOST=smtp.gmail.com, MAIL_PORT=587, MAIL_USERNAME and MAIL_FROM_ADDRESS your Gmail address, and MAIL_PASSWORD a 16-letter App Password, not your Gmail password.');

        return 1;
    }

    $this->info("Sent to {$to} with the {$mailer} mailer, from ".config('mail.from.address').'.');

    return 0;
})->purpose('Send a test email, to check the mail settings');
