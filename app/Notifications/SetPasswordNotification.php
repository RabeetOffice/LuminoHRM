<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your account is ready, choose a password." The link goes to the starter
 * kit's reset-password page and carries a token from Laravel's password broker,
 * so it expires on the broker's schedule. Queued: the database queue is drained
 * every minute in production, and the admin's request does not wait for SMTP.
 */
class SetPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** The payload holds the raw token: encrypted at rest, and not retried forever. */
    public int $tries = 3;

    public function __construct(public readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $company = (string) settings('company.name');

        return (new MailMessage)
            ->subject('Set your Lumino HRM password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line("An account has been created for you at {$company}.")
            ->line('Choose a password with the button below, then sign in.')
            ->action('Set your password', $url)
            ->line("This link expires in {$minutes} minutes. If it has expired, ask an administrator to send you a new one.")
            ->line('If you were not expecting this email, you can ignore it.');
    }
}
