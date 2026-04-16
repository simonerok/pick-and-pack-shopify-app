<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class CoworkerResetPasswordNotification extends ResetPassword implements ShouldQueue
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
            ->subject('You have been invited to SBB pick & pack platform')
            ->greeting('Hi ' . $notifiable->name . '!')
            ->line('A colleague has invited you to join SBB pick & pack platform.')
            ->line('Click the button below to set your password and activate your account.')
            ->action('Set your password', $resetUrl)
            ->line('If you did not expect this invitation, you can ignore this email.')
            ->salutation('Best regards, the SBB team.');
    }
}
