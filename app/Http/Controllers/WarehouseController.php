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
    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'director', 'financist']);
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
        abort_unless($this->canManage($request), 403, 'Приход оформляет бухгалтер, директор или админ.');

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

    public function destroyMaterial(Request $request, Material $material): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        abort_unless($request->user()->worksInCompany($material->company_id ? (int) $material->company_id : null), 403);

        $material->delete();

        return back()->with('success', 'Позиция склада удалена.');
    }
}
