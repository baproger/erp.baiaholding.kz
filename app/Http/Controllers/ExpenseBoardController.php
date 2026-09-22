<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Расходы» — рабочее место бухгалтера: сверху заявки, которые ждут проверки,
 * с ОТКРЫТЫМИ чеками (чтобы не кликать по каждому), ниже — все расходы.
 *
 * Расчётов не содержит: суммы берутся из тех же данных, что и на Финансах,
 * поэтому цифры двух страниц сходятся по построению.
 */
class ExpenseBoardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        // Завсклад (supplier) допущен на доску ради подтверждения металла, но
        // видит ТОЛЬКО расходы типа «Металл» — зарплаты и выплаты сотрудникам
        // ему показывать нельзя (правило от 22.09.2026).
        abort_unless($user->hasAnyRole(['admin', 'director', 'financist', 'supplier']), 403);
        $metalOnly = $user->hasRole('supplier') && ! $user->hasAnyRole(['admin', 'director', 'financist']);

        $companyId = CurrentCompany::id() ?: null;
        $month = preg_match('/^\d{4}-\d{2}$/', $request->string('month')->toString())
            ? $request->string('month')->toString() : now()->format('Y-m');
        $start = $month.'-01';
        $end = Carbon::parse($start)->endOfMonth()->toDateString();

        // Ждут проверки — БЕЗ фильтра месяца: заявка не должна потеряться
        // из-за того, что бухгалтер смотрит другой период.
        $pending = Expense::where('status', 'pending')
            ->when($metalOnly, fn ($q) => $q->where('type', 'metal'))
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w
                ->where('company_id', $c)->orWhereNull('company_id')))
            ->with(['responsible:id,name,avatar', 'category:id,name', 'expenseable'])
            ->orderBy('date')->orderBy('id')
            ->get()
            ->map(fn ($e) => $this->row($e, $user));

        $confirmed = Expense::where('status', 'confirmed')
            ->when($metalOnly, fn ($q) => $q->where('type', 'metal'))
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w
                ->where('company_id', $c)->orWhereNull('company_id')))
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->with(['responsible:id,name', 'category:id,name', 'employee:id,name', 'confirmedBy:id,name', 'expenseable'])
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->map(fn ($e) => $this->row($e, $user));

        return Inertia::render('Finance/Expenses', [
            'month' => $month,
            'pending' => $pending->values(),
            'confirmed' => $confirmed->values(),
            'totals' => [
                'pending' => round($pending->sum('amount'), 2),
                'confirmed' => round($confirmed->sum('amount'), 2),
                'pending_count' => $pending->count(),
            ],
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $user->hasAnyRole(['admin', 'financist']),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Expense $e, ?\App\Models\User $user = null): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            // Кто может подтвердить именно этот расход: бухгалтер/админ — любой,
            // завсклад — только металл (правило от 22.09.2026).
            'can_confirm' => $user !== null && ($user->hasAnyRole(['admin', 'financist'])
                || ($user->hasRole('supplier') && $e->type === 'metal')),
            'date' => optional($e->date)->toDateString(),
            'amount' => (float) $e->amount,
            'description' => $e->description,
            'category' => $e->category?->name,
            'status' => $e->status,
            'payment_method' => $e->payment_method,
            'author' => $e->responsible ? ['id' => $e->responsible->id, 'name' => $e->responsible->name] : null,
            // Кто подтвердил (оплатил): бухгалтер по имени; пусто у confirmed
            // без подтверждающего — это провела сама система (склад и т.п.).
            'confirmer' => $e->confirmedBy ? ['id' => $e->confirmedBy->id, 'name' => $e->confirmedBy->name] : null,
            // Выплата сотруднику (аванс/долг) — видно, кому и за что.
            'employee' => $e->employee ? ['id' => $e->employee->id, 'name' => $e->employee->name] : null,
            'payout' => match ($e->employee_payout) {
                'advance' => 'Аванс',
                'debt' => 'Долг',
                'salary_payout' => 'Выплата ЗП',
                'bonus_payout' => 'Выплата бонуса',
                default => null,
            },
            'has_file' => (bool) $e->file_path,
            // Чек показывается прямо в карточке: картинку — встроенно,
            // остальное (PDF) — ссылкой.
            'is_image' => $e->file_path && preg_match('/\.(jpe?g|png|webp|gif)$/i', $e->file_path) === 1,
            'deal_id' => $e->expenseable_type === 'deal' ? $e->expenseable_id : null,
            // Материал со склада: смета дизайнера по сделке (бухгалтер сверяет перед подтверждением).
            'material' => (bool) $e->material_id,
            'estimate' => $e->material_id && $e->expenseable_type === 'deal'
                ? \App\Models\Document::where('documentable_type', 'deal')->where('documentable_id', $e->expenseable_id)
                    ->where('kind', 'estimate')->where('is_active', true)->latest()->first(['id', 'name'])
                : null,
            // Ссылка «по какой сделке / заказу цеха» — номер и заказчик.
            'link' => $e->expenseable ? [
                'route' => $e->expenseable_type === 'project' ? 'projects.show' : 'deals.show',
                'id' => $e->expenseable_id,
                'number' => $e->expenseable->number ?? null,
                'name' => $e->expenseable_type === 'project'
                    ? ($e->expenseable->deal?->company_name ?? $e->expenseable->name ?? null)
                    : ($e->expenseable->company_name ?? null),
            ] : null,
        ];
    }
}
