<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Завскладу (снабженцу): материал списан со склада на сделку/заказ. */
class MaterialWrittenOff extends Notification
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
        $entity = $this->expense->expenseable;
        $url = match (true) {
            $entity instanceof \App\Models\Project => route('projects.show', $entity->id, absolute: false),
            $entity !== null => route('deals.show', $entity->id, absolute: false),
            default => route('warehouse.index', absolute: false),
        };
        $material = $this->expense->material;
        $qty = rtrim(rtrim(number_format((float) $this->expense->qty, 2, '.', ''), '0'), '.');

        return [
            'type' => 'material_written_off',
            'title' => 'Материал списан со склада',
            'message' => ($material?->name ?? 'Материал').' × '.$qty.' '.($material?->unit ?? '')
                .($entity?->number ? ' · '.$entity->number : ''),
            'expense_id' => $this->expense->id,
            'url' => $url,
        ];
    }
}
