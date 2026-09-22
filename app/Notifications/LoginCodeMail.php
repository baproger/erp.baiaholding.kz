<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Код входа на почту — только страховка для администратора (LoginCodeController::email). */
class LoginCodeMail extends Notification
{
    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Код входа в BAIA ERP')
            ->greeting('Здравствуйте, '.$notifiable->name.'!')
            ->line('Ваш код входа: **'.$this->code.'**')
            ->line('Код действует 24 часа и сгорает после первого использования.')
            ->line('Если вы не запрашивали код — просто проигнорируйте письмо.');
    }
}
