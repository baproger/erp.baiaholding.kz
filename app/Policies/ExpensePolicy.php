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
     * для правки оно не заморожено. Удаление сюда не относится: его delete()
     * ниже запрещает всем, кроме бухгалтера/админа, без исключений.
     */
    public function update(User $u, Expense $e): Response
    {
        return $u->can('expense.update') ? $this->unlessFrozen($u, $e, 'изменить') : Response::deny();
    }

    /**
     * Удалять расходы может ТОЛЬКО бухгалтер или админ (просьба владельца
     * 08.08.2026) — включая ещё не подтверждённые. Расход завёл менеджер и
     * передумал? Пусть бухгалтер удалит: деньги компании, и следов удаления
     * не должно зависеть от того, успел бухгалтер посмотреть или нет.
     * Проверка ролью, а не только правом: право `expense.delete` роль может
     * получить обратно через админку, а правило должно держаться.
     */
    public function delete(User $u, Expense $e): Response
    {
        if (! $u->hasAnyRole(['admin', 'financist'])) {
            return Response::deny('Удалять расходы может только бухгалтер или админ.');
        }

        return $u->can('expense.delete') ? Response::allow() : Response::deny();
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
