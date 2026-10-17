<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(private Invitation $invitation, public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Undangan bergabung ke '.$this->invitation->tenant->name)
            ->line('Anda diundang bergabung sebagai '.$this->invitation->role->label().'.')
            ->action('Terima undangan', route('invitations.show', $this->token))
            ->line('Tautan berlaku sampai '.$this->invitation->expires_at->toDayDateTimeString().' dan hanya dapat dipakai sekali.');
    }
}
