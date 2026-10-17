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
            ->subject('Invitation to join '.$this->invitation->tenant->name)
            ->line('You have been invited to join as '.$this->invitation->role->label().'.')
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This link is valid until '.$this->invitation->expires_at->toDayDateTimeString().' and can only be used once.');
    }
}
