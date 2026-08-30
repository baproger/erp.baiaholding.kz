<?php

namespace App\Listeners;

use App\Support\LiveStamp;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Cache;

/** Новое уведомление в БД → штамп «notifications» получателя + сброс шапки колокольчика. */
class BumpLiveStampOnNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }
        $id = $event->notifiable->getKey();
        LiveStamp::bump($id, 'notifications');
        Cache::forget('notif_head.'.$id);
    }
}
