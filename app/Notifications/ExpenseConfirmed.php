<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Автору расхода: бухгалтер подтвердил расход (нал/банк). */
class ExpenseConfirmed extends Notification
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
        // Автору: расход по сделке/заказу → его карточка, заявка компании →
        // «Мои расходы» (там она теперь со статусом «Оплачен» и способом).
        $entity = $this->expense->expenseable;
        $url = match (true) {
            $entity instanceof \App\Models\Project => route('projects.show', $entity->id, absolute: false),
            $entity !== null => route('deals.show', $entity->id, absolute: false),
            default => route('myExpenses.index', absolute: false),
        };

        return [
            'type' => 'expense_confirmed',
            'title' => 'Расход подтверждён',
            'message' => number_format((float) $this->expense->amount, 0, '.', ' ').' ₸ · '.($this->expense->payment_method === 'cash' ? 'наличные' : 'банк'),
            'expense_id' => $this->expense->id,
            'url' => $url,
        ];
    }
}
