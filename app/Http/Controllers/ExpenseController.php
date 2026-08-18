<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    private function assertOwnership(User $user, ?Model $entity): void
    {
        // Изоляция фирм: расходы чужой компании (BAIA/ASU) недоступны никому,
        // кто к этой компании не привязан, — включая финансиста и директора.
        $companyId = $entity instanceof Project ? $entity->deal?->company_id : $entity?->company_id;
        abort_unless($entity === null || $user->worksInCompany($companyId ? (int) $companyId : null), 403);

        // Менеджер: расход по сделке/заказу — только по СВОЕЙ сделке. Расход
        // компании ($entity === null) сюда не попадает: заявку бухгалтеру
        // подаёт любой сотрудник, менеджер — тоже.
        if ($entity !== null && $user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'director', 'financist'])) {
            abort_unless($entity->responsible_user_id === $user->id, 403);
        }
    }

    private function resolve(?string $type, ?int $id): ?Model
    {
        if (! $id) {
            return null;
        }

        return $type === 'project' ? Project::find($id) : Deal::find($id);
    }

    /**
     * Контроль маржи: подтверждённые расходы сделки превысили 60% от суммы
     * договора → уведомить финансистов (порог пересекается один раз).
     */
    private function checkExpenseThreshold(?Model $entity, float $addedAmount): void
    {
        $deal = $entity instanceof Project ? $entity->deal : $entity;
        if (! $deal instanceof Deal || (float) $deal->budget <= 0) {
            return;
        }

        $spent = (float) Expense::where('status', 'confirmed')
            ->where(fn ($q) => $q
                ->where(fn ($d) => $d->where('expenseable_type', 'deal')->where('expenseable_id', $deal->id))
                ->orWhere(fn ($p) => $p->where('expenseable_type', 'project')
                    ->whereIn('expenseable_id', Project::where('deal_id', $deal->id)->select('id'))))
            ->sum('amount');

        $limit = (float) $deal->budget * 0.6;
        if ($spent > $limit && ($spent - $addedAmount) <= $limit) {
            User::where('is_active', true)->role('financist')->get()
                ->each(fn (User $u) => $u->notify(new \App\Notifications\ExpenseThresholdExceeded($deal, $spent)));
        }
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $this->authorize('create', Expense::class);
        $entity = $this->resolve($request->input('expenseable_type', 'deal'), (int) $request->input('expenseable_id'));
        $this->assertOwnership($request->user(), $entity);

        $data = $request->validated();
        unset($data['file']);
        // Чек хранится вне public-корня (storage/app/private), как и документы.
        $data['file_path'] = $request->hasFile('file') ? $request->file('file')->store('receipts', 'local') : null;
        // Автор проставляется автоматически — заполнить расход за другого нельзя.
        $data['responsible_user_id'] = $request->user()->id;
        $data['type'] ??= 'direct';

        // Защита от повторной отправки формы: тот же автор, та же сумма,
        // описание и привязка меньше минуты назад — почти наверняка дубль.
        // Складские списания не трогаем: у них своя механика и остаток.
        if (empty($data['material_id'])) {
            $dupe = Expense::where('responsible_user_id', $request->user()->id)
                ->where('amount', $data['amount'] ?? 0)
                ->where('description', $data['description'] ?? null)
                ->where('expenseable_type', $entity ? $data['expenseable_type'] : null)
                ->where('expenseable_id', $entity?->id)
                ->where('created_at', '>=', now()->subMinute())->exists();
            if ($dupe) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'Точно такой же расход уже добавлен только что — похоже на повторную отправку. Если это не дубль, подождите минуту.',
                ]);
            }
        }

        // Расход КОМПАНИИ (без сделки): аренда/комуслуги/интернет/бензин и т.п.
        // Вводит только бухгалтер/админ, категория обязательна, склад — нельзя.
        if ($entity === null) {
            // Заявку подаёт ЛЮБОЙ сотрудник — это «счёт бухгалтеру на оплату».
            // Бухгалтер проверяет чек и оплачивает; до подтверждения расход
            // pending и на кассу/маржу не влияет (см. ExpensePolicy).
            if (empty($data['category_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'category_id' => 'Выберите категорию расхода (аренда, интернет, бензин…).',
                ]);
            }
            if (! empty($data['material_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'material_id' => 'Списание со склада делается из карточки сделки/заказа.',
                ]);
            }
            $data['company_id'] = \App\Support\CurrentCompany::id() ?: null;
        }

        // Расход по материалам: списываем остаток со склада компании сделки.
        if (! empty($data['material_id'])) {
            $material = \App\Models\Material::findOrFail($data['material_id']);

            $entityCompanyId = $entity instanceof Project ? $entity->deal?->company_id : $entity?->company_id;
            if ($material->company_id && (int) $material->company_id !== (int) $entityCompanyId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'material_id' => 'Материал со склада другой компании.',
                ]);
            }
            // Первичная проверка (быстрый отказ + подсказка остатка); финальная,
            // защищённая от гонки, — под блокировкой строки внутри транзакции.
            if ((float) $material->quantity < (float) $data['qty']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'qty' => 'Недостаточно на складе: остаток '.rtrim(rtrim(number_format((float) $material->quantity, 2, '.', ' '), '0'), '.').' '.$material->unit.'.',
                ]);
            }

            // Сумма считается автоматически: количество × закупочная цена
            // (последняя цена прихода на материале). Если цена не заведена
            // (старые позиции) — сумма обязательна вручную: без неё возник бы
            // «подтверждённый» расход на 0 ₸ со списанием склада.
            if ((float) $material->price > 0) {
                $data['amount'] = round((float) $data['qty'] * (float) $material->price, 2);
            } elseif ((float) ($data['amount'] ?? 0) <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'У материала не заведена закупочная цена — укажите сумму расхода вручную.',
                ]);
            }
            $data['amount'] = (float) $data['amount'];

            // Способ оплаты обнуляем: деньги за материал ушли при ЗАКУПЕ на
            // склад (расход прихода), списание кассу второй раз не трогает.
            $data['payment_method'] = null;
            $data['description'] = trim(($data['description'] ?? '')) !== ''
                ? $data['description']
                : 'Материал: '.$material->name.' × '.rtrim(rtrim(number_format((float) $data['qty'], 2, '.', ''), '0'), '.').' '.$material->unit;

            // Списание подтверждает бухгалтер (правило от 18.08.2026): расход
            // менеджера — pending, склад НЕ трогаем до подтверждения. Бухгалтер/
            // админ списывает сразу. Завскладу (снабженец) — уведомление.
            $isAccountant = $request->user()->hasAnyRole(['admin', 'financist']);
            if (! $isAccountant) {
                $data['status'] = 'pending';
                $expense = Expense::create($data);
                $this->notifyAccountants($expense, $entity);

                return back()->with('success', 'Списание отправлено бухгалтеру — остаток спишется после подтверждения.');
            }

            $data['status'] = 'confirmed';
            $data['confirmed_by'] = $request->user()->id;
            $data['confirmed_at'] = now();

            $expense = null;
            \Illuminate\Support\Facades\DB::transaction(function () use ($data, $material, &$expense) {
                // Блокируем строку материала и перечитываем остаток ВНУТРИ
                // транзакции: два параллельных списания не уведут склад в минус.
                $locked = \App\Models\Material::whereKey($material->id)->lockForUpdate()->first();
                if ((float) $locked->quantity < (float) $data['qty']) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'qty' => 'Недостаточно на складе: остаток '.rtrim(rtrim(number_format((float) $locked->quantity, 2, '.', ' '), '0'), '.').' '.$locked->unit.'.',
                    ]);
                }
                $expense = Expense::create($data);
                $locked->decrement('quantity', $data['qty']);
            });

            $this->notifyWarehouse($expense);
            $this->checkExpenseThreshold($entity, (float) $data['amount']);

            return back()->with('success', 'Расход по материалам добавлен — остаток на складе списан.');
        }

        // Прочий расход: бухгалтер/админ подтверждают сразу; расход менеджера
        // (и директора) ждёт подтверждения бухгалтера — чек + нал/банк.
        $isAccountant = $request->user()->hasAnyRole(['admin', 'financist']);
        $data['status'] = $isAccountant ? 'confirmed' : 'pending';
        // Заявка сотрудника — это ещё не выдача денег: откуда платить, решает
        // бухгалтер при подтверждении. Иначе касса/банк уменьшились бы по
        // выбору автора, ещё до фактической оплаты.
        if (! $isAccountant && $entity === null) {
            unset($data['payment_method']);
        }
        if ($isAccountant) {
            $data['confirmed_by'] = $request->user()->id;
            $data['confirmed_at'] = now();
        }
        // Способ оплаты (нал/банк) автор выбирает при создании; у pending-расхода
        // бухгалтер может поменять его при подтверждении.

        $expense = Expense::create($data);

        if ($isAccountant) {
            $this->checkExpenseThreshold($entity, (float) $expense->amount);
        }

        if (! $isAccountant) {
            $this->notifyAccountants($expense, $entity);

            return back()->with('success', 'Расход отправлен бухгалтеру на подтверждение.');
        }

        return back()->with('success', 'Расход добавлен.');
    }

    /**
     * Бухгалтеру: уведомление + задача «Подтвердить расход…» на сделке/заказе.
     */
    private function notifyAccountants(Expense $expense, ?Model $entity): void
    {
        $title = 'Подтвердить расход #'.$expense->id.' — '.number_format((float) $expense->amount, 0, '.', ' ').' ₸'
            .($entity?->number ? ' ('.$entity->number.')' : '');

        $financists = User::where('is_active', true)->role('financist')->get();
        foreach ($financists as $fin) {
            if ($entity && method_exists($entity, 'tasks')) {
                $entity->tasks()->create([
                    'title' => $title,
                    'status' => 'new',
                    'priority' => 'high',
                    'assignee_id' => $fin->id,
                    'creator_id' => $expense->responsible_user_id ?? $fin->id,
                    'start_date' => now(),
                    'due_date' => now()->addDays(3),
                ]);
            }
            $fin->notify(new \App\Notifications\ExpensePending($expense));
        }
    }

    /**
     * Подтверждение расхода бухгалтером: обязательный чек (уже приложенный или
     * загружаемый сейчас) + способ оплаты нал/банк (касса).
     */
    public function confirm(Request $request, Expense $expense): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Расход подтверждает бухгалтер или админ.');
        $this->assertOwnership($request->user(), $expense->expenseable);

        if ($expense->status === 'confirmed') {
            return back()->with('error', 'Расход уже подтверждён.');
        }

        // Списание материала: чек и способ оплаты не нужны (деньги ушли при
        // закупе на склад) — подтверждение и есть момент списания остатка.
        if ($expense->material_id) {
            try {
                \Illuminate\Support\Facades\DB::transaction(function () use ($request, $expense) {
                    $locked = \App\Models\Material::whereKey($expense->material_id)->lockForUpdate()->first();
                    if (! $locked || (float) $locked->quantity < (float) $expense->qty) {
                        throw new \RuntimeException('Недостаточно на складе: остаток '
                            .rtrim(rtrim(number_format((float) ($locked?->quantity ?? 0), 2, '.', ' '), '0'), '.').' '.($locked?->unit ?? '').'.');
                    }
                    $expense->update([
                        'status' => 'confirmed',
                        'confirmed_by' => $request->user()->id,
                        'confirmed_at' => now(),
                    ]);
                    $locked->decrement('quantity', $expense->qty);
                });
            } catch (\RuntimeException $e) {
                return back()->with('error', $e->getMessage());
            }

            $this->finishConfirmation($request, $expense);
            $this->notifyWarehouse($expense);

            return back()->with('success', 'Списание подтверждено — остаток на складе списан.');
        }

        $data = $request->validate([
            'payment_method' => ['required', \Illuminate\Validation\Rule::in(['cash', 'bank'])],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
        ], ['payment_method.required' => 'Выберите способ оплаты: наличные или банк.']);

        if ($request->hasFile('file')) {
            if ($expense->file_path) {
                Storage::disk('local')->delete($expense->file_path);
            }
            $expense->file_path = $request->file('file')->store('receipts', 'local');
        }
        if (! $expense->file_path) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'Без чека расход не подтверждается — прикрепите фото или PDF.',
            ]);
        }

        $expense->update([
            'file_path' => $expense->file_path,
            'status' => 'confirmed',
            'payment_method' => $data['payment_method'],
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
        ]);

        $this->finishConfirmation($request, $expense);

        return back()->with('success', 'Расход подтверждён ('.($data['payment_method'] === 'cash' ? 'наличные' : 'банк').').');
    }

    /**
     * Общий «хвост» подтверждения: закрыть задачи-гейты, погасить счётчики,
     * уведомить автора и остальных бухгалтеров, проверить порог расходов.
     */
    private function finishConfirmation(Request $request, Expense $expense): void
    {
        // Закрываем задачи «Подтвердить расход #N …» у бухгалтеров.
        $gateTasks = \App\Models\Task::where('title', 'like', 'Подтвердить расход #'.$expense->id.' %')
            ->where('status', '!=', 'done')->get();
        $gateTasks->each(fn ($t) => $t->update(['status' => 'done', 'completed_at' => now()]));
        // Действие выполнено — гасим красный счётчик у всех бухгалтеров.
        \App\Support\NotificationResolver::expense($expense->id);
        \App\Support\NotificationResolver::tasks($gateTasks->pluck('id'));

        // Автору — уведомление о подтверждении.
        $expense->responsible?->notify(new \App\Notifications\ExpenseConfirmed($expense));
        $this->checkExpenseThreshold($expense->expenseable, (float) $expense->amount);

        // Остальным бухгалтерам — «расход уже подтверждён (Имя)», чтобы не
        // подтверждали повторно. Кроме того, кто подтвердил, и автора.
        User::where('is_active', true)->role('financist')
            ->where('id', '!=', $request->user()->id)
            ->where('id', '!=', $expense->responsible_user_id)
            ->get()
            ->each(fn ($fin) => $fin->notify(new \App\Notifications\ExpenseHandled($expense, $request->user())));
    }

    /**
     * Завскладу (роль «Снабженец»): материал списан со склада — с ссылкой на
     * сделку/заказ, по которому ушёл материал.
     */
    private function notifyWarehouse(?Expense $expense): void
    {
        if (! $expense || ! $expense->material_id) {
            return;
        }
        User::where('is_active', true)->role('supplier')->get()
            ->each(fn ($u) => $u->notify(new \App\Notifications\MaterialWrittenOff($expense)));
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->authorize('update', $expense);
        $this->assertOwnership($request->user(), $expense->expenseable);
        // Заявка компании (без сделки): пока она pending — это черновик АВТОРА,
        // чужую заявку правит только бухгалтер/админ.
        if (! $expense->expenseable_type && ! $request->user()->hasAnyRole(['admin', 'financist'])) {
            abort_unless($expense->responsible_user_id === $request->user()->id, 403);
        }

        $data = $request->validated();
        unset($data['file']);
        // Материал/количество после создания не меняются (иначе разъедется склад) —
        // удалите расход (остаток вернётся) и создайте заново.
        unset($data['material_id'], $data['qty']);
        // Статус, способ оплаты, подтверждающий и полиморфная привязка — НЕ через
        // update(): статус/оплату ставит только confirm() (бухгалтер, с чеком),
        // родитель расхода неизменен. Иначе менеджер сам себе подтвердил бы расход
        // (обход бухгалтера) или увёл его на чужую сделку/компанию.
        unset($data['status'], $data['payment_method'], $data['confirmed_by'], $data['confirmed_at'],
            $data['expenseable_type'], $data['expenseable_id']);
        // Сумма материального расхода — производная (кол-во × цена), руками не
        // правится: попытка её изменить получает честную ошибку, а не молчаливый
        // игнор с флешем «Расход обновлён».
        if ($expense->material_id && (float) ($expense->material?->price ?? 0) > 0) {
            if (isset($data['amount']) && abs((float) $data['amount'] - (float) $expense->amount) > 0.005) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'Сумма расхода по материалам считается автоматически (количество × цена) и не редактируется — удалите расход (остаток вернётся) и создайте заново.',
                ]);
            }
            unset($data['amount']);
        } elseif (! isset($data['amount'])) {
            // amount необязателен при material_id: null в NOT NULL колонку → 500.
            unset($data['amount']);
        }
        if ($request->hasFile('file')) {
            if ($expense->file_path) {
                Storage::disk('local')->delete($expense->file_path);
            }
            $data['file_path'] = $request->file('file')->store('receipts', 'local');
        }
        // responsible_user_id намеренно не трогаем — автор расхода неизменен.
        $expense->update($data);

        return back()->with('success', 'Расход обновлён.');
    }

    public function receipt(Expense $expense): StreamedResponse
    {
        $this->authorize('view', $expense);
        $this->assertOwnership(request()->user(), $expense->expenseable);
        // Чек заявки компании — автору, сотруднику из выплаты (аванс/долг)
        // и руководству; чужие заявки сотрудникам не показываем.
        if (! $expense->expenseable_type && ! request()->user()->hasAnyRole(['admin', 'director', 'financist'])) {
            abort_unless(in_array(request()->user()->id, [$expense->responsible_user_id, $expense->employee_id], true), 403);
        }

        abort_unless($expense->file_path && Storage::disk('local')->exists($expense->file_path), 404);

        $name = 'чек-' . $expense->id . '.' . pathinfo($expense->file_path, PATHINFO_EXTENSION);

        // Отдаём inline — чек открывается в браузере на просмотр, без скачивания.
        // nosniff: файл загружен пользователем, запрещаем браузеру угадывать тип (защита от XSS).
        return Storage::disk('local')->response($expense->file_path, $name, [
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);
        $this->assertOwnership(request()->user(), $expense->expenseable);

        // Удаление подтверждённого расхода по материалам возвращает количество
        // на склад; pending склад ещё не трогал — возвращать нечего.
        $returned = $expense->material_id && $expense->qty && $expense->material && $expense->status === 'confirmed';
        \Illuminate\Support\Facades\DB::transaction(function () use ($expense, $returned) {
            if ($returned) {
                $expense->material->increment('quantity', $expense->qty);
            }
            $expense->delete();
        });
        // Расхода больше нет — «ждёт подтверждения» гасим у всех бухгалтеров.
        \App\Support\NotificationResolver::expense($expense->id);

        \App\Support\FinanceAudit::notifyDeleted(
            'Расход на '.number_format((float) $expense->amount, 0, '.', ' ').' ₸'.($expense->description ? ' («'.\Illuminate\Support\Str::limit($expense->description, 60).'»)' : ''),
            \App\Support\FinanceAudit::linkTo($expense->expenseable)
        );

        return back()->with('success', $returned ? 'Расход удалён — остаток возвращён на склад.' : 'Расход удалён.');
    }
}
