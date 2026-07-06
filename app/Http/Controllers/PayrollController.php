<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    public function index(Request $request, PayrollService $payroll): Response
    {
        $user = $request->user();
        abort_unless($user->can('payroll.view'), 403);

        $leadership = $user->hasAnyRole(['admin', 'director', 'financist']);
        $rate = ((float) Setting::get('bonus_percent', 10)) / 100;
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        // Single source of truth for the payroll math (shared with Analytics & Finance).
        $rows = $payroll->perUser()->sortByDesc('bonus')->values();
        if (! $leadership) {
            $rows = $rows->filter(fn ($r) => $r['uid'] === $user->id)->values();
        }

        return Inertia::render('Payroll/Index', [
            'rows' => $rows,
            'leadership' => $leadership,
            'rate' => $rate * 100,
            'taxRate' => $taxRate * 100,
            'totals' => [
                'budget' => (float) $rows->sum('budget'),
                'tax' => (float) $rows->sum('tax'),
                'expense' => (float) $rows->sum('expense'),
                'bonus' => (float) $rows->sum('bonus'),
                'company' => (float) $rows->sum('company'),
            ],
        ]);
    }
}
