<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ForgotPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable): MailMessage
    {
        $path = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false);

        $parts = parse_url(config('app.url'));
        $mailBase = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $resetUrl = $mailBase . $path;

        return (new MailMessage())
            ->subject('Reset your SBB pick & pack password')
            ->greeting('Hi ' . $notifiable->name . '!')
            ->line('We received a request to reset your password for your SBB pick & pack account.')
            ->line('Click the button below to choose a new password.')
            ->action('Reset password', $resetUrl)
            ->line('If you did not request this, you can safely ignore this email.')
            ->salutation('Best regards, the SBB team.');
    }
}
