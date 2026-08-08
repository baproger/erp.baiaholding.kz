<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    public function viewAny(User $u): bool { return $u->can('expense.viewAny'); }
    public function view(User $u, Expense $e): bool { return $u->can('expense.view'); }
    public function create(User $u): bool { return $u->can('expense.create'); }

    /**
     * Расход, который ПОДТВЕРДИЛ БУХГАЛТЕР (приложил чек, выбрал нал/банк,
     * деньги ушли из кассы), автор больше не трогает: иначе он задним числом
     * ломает бухгалтеру кассу. Менять и удалять такой расход может только
     * бухгалтер/админ; пока расход pending — это черновик автора.
     *
     * Ключ — confirmed_by, а не status: списание со склада система проводит
     * сама (status=confirmed, confirmed_by пустой), бухгалтер его не видел —
     * там менеджер по-прежнему «удали и заведи заново», как учит форма.
     */
    public function update(User $u, Expense $e): Response
    {
        return $u->can('expense.update') ? $this->unlessFrozen($u, $e, 'изменить') : Response::deny();
    }

    public function delete(User $u, Expense $e): Response
    {
        return $u->can('expense.delete') ? $this->unlessFrozen($u, $e, 'удалить') : Response::deny();
    }

    /** Причину отказа отдаём текстом — иначе менеджер видит голое «403». */
    private function unlessFrozen(User $u, Expense $e, string $action): Response
    {
        if ($e->confirmed_by !== null && ! $u->hasAnyRole(['admin', 'financist'])) {
            return Response::deny("Расход уже подтверждён бухгалтером — {$action} его может только бухгалтер.");
        }

        return Response::allow();
    }
}
