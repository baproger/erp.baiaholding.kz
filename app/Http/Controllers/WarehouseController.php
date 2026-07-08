<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Material;
use App\Models\MaterialReceipt;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Склад: у каждой компании (BAIA/ASU) свой. Приход оформляет бухгалтер /
 * директор / админ; менеджеры видят остатки (для расходов по материалам).
 */
class WarehouseController extends Controller
{
    /** Управление складом (приход, правка, удаление) — только бухгалтер и админ. */
    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'financist']);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'director', 'financist', 'manager']), 403);

        $allMode = CurrentCompany::id() === 0;
        $materials = Material::forCurrentCompany()
            ->when($allMode, fn ($q) => $q->with('company:id,name'))
            ->orderBy('name')->get();

        $receipts = MaterialReceipt::whereIn('material_id', $materials->pluck('id'))
            ->with(['material:id,name,unit', 'user:id,name'])
            ->latest()->limit(30)->get();

        return Inertia::render('Warehouse/Index', [
            'materials' => $materials,
            'receipts' => $receipts,
            'units' => Deal::UNITS,
            'canManage' => $this->canManage($request),
            'allMode' => $allMode,
            'companyName' => $allMode ? 'Все компании' : (CurrentCompany::get()?->name ?? ''),
        ]);
    }

    /** Приход товара: существующий материал или новая позиция. */
    public function receipt(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Приход оформляет бухгалтер или админ.');

        $data = $request->validate([
            'material_id' => ['nullable', 'exists:materials,id'],
            'name' => ['required_without:material_id', 'nullable', 'string', 'max:255'],
            'unit' => ['nullable', Rule::in(Deal::UNITS)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $companyId = CurrentCompany::id() ?: null;
        if (! $companyId && empty($data['material_id'])) {
            return back()->with('error', 'Переключитесь на конкретную компанию (BAIA или ASU), чтобы оформить приход.');
        }

        DB::transaction(function () use ($data, $request, $companyId) {
            $material = ! empty($data['material_id'])
                ? Material::findOrFail($data['material_id'])
                : Material::firstOrCreate(
                    ['company_id' => $companyId, 'name' => trim($data['name'])],
                    ['unit' => $data['unit'] ?? 'штук', 'quantity' => 0]
                );

            // Изоляция фирм: приход только на склад своей компании.
            abort_unless($request->user()->worksInCompany($material->company_id ? (int) $material->company_id : null), 403);

            $material->receipts()->create([
                'user_id' => $request->user()->id,
                'quantity' => $data['quantity'],
                'date' => $data['date'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
            ]);
            $material->increment('quantity', $data['quantity']);
        });

        return back()->with('success', 'Приход оформлен — остаток обновлён.');
    }

    /**
     * Правка прихода: разница количества корректирует остаток материала
     * (в минус остаток уйти не может).
     */
    public function updateReceipt(Request $request, MaterialReceipt $receipt): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Приходы редактирует бухгалтер или админ.');
        abort_unless($request->user()->worksInCompany($receipt->material?->company_id ? (int) $receipt->material->company_id : null), 403);

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $delta = (float) $data['quantity'] - (float) $receipt->quantity;
        $material = $receipt->material;
        if ((float) $material->quantity + $delta < 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'quantity' => 'Так остаток уйдёт в минус: на складе '.number_format((float) $material->quantity, 2, '.', ' ').' '.$material->unit.' (часть уже списана в расходы).',
            ]);
        }

        DB::transaction(function () use ($receipt, $material, $data, $delta) {
            $receipt->update([
                'quantity' => $data['quantity'],
                'date' => $data['date'] ?? $receipt->date,
                'note' => $data['note'] ?? null,
            ]);
            if ($delta > 0) {
                $material->increment('quantity', $delta);
            } elseif ($delta < 0) {
                $material->decrement('quantity', abs($delta));
            }
        });

        return back()->with('success', 'Приход обновлён — остаток пересчитан.');
    }

    /** Удаление прихода: количество снимается с остатка (в минус нельзя). */
    public function destroyReceipt(Request $request, MaterialReceipt $receipt): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Приходы удаляет бухгалтер или админ.');
        abort_unless($request->user()->worksInCompany($receipt->material?->company_id ? (int) $receipt->material->company_id : null), 403);

        $material = $receipt->material;
        if ((float) $material->quantity - (float) $receipt->quantity < 0) {
            return back()->with('error', 'Нельзя удалить приход: остаток уйдёт в минус (часть уже списана в расходы).');
        }

        DB::transaction(function () use ($receipt, $material) {
            $material->decrement('quantity', $receipt->quantity);
            $receipt->delete();
        });

        return back()->with('success', 'Приход удалён — остаток пересчитан.');
    }

    public function destroyMaterial(Request $request, Material $material): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        abort_unless($request->user()->worksInCompany($material->company_id ? (int) $material->company_id : null), 403);

        $material->delete();

        return back()->with('success', 'Позиция склада удалена.');
    }
}
