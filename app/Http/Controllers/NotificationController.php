<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function markRead(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->where('id', $id)->update(['read_at' => now()]);
        // Шапка кэширует уведомления на 15с — после прочтения сбрасываем сразу.
        \Illuminate\Support\Facades\Cache::forget('notif_head.'.$request->user()->id);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();
        \Illuminate\Support\Facades\Cache::forget('notif_head.'.$request->user()->id);

        return back()->with('success', 'Все уведомления прочитаны.');
    }
}
