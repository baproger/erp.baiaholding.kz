<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Бухгалтеру: менеджер добавил расход — нужно подтвердить (чек + нал/банк). */
class ExpensePending extends Notification
{
    use Queueable;

    public function __construct(public Expense $expense) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'expense_pending',
            'title' => 'Расход ждёт подтверждения',
            'message' => number_format((float) $this->expense->amount, 0, '.', ' ').' ₸ — '.($this->expense->description ?: 'без описания'),
            'expense_id' => $this->expense->id,
        ];
    }
}
