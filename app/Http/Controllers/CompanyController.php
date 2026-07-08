<?php

namespace App\Http\Controllers;

use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    /**
     * Header switcher: change the current firm without re-login.
     */
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate(['company_id' => ['required', 'integer', 'min:0']]);
        $id = (int) $validated['company_id'];

        // 0 = «Все компании» — общий отчёт по обеим фирмам (бухгалтер/админ).
        if ($id === 0) {
            abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Режим «Все компании» доступен бухгалтеру и админу.');
            CurrentCompany::set(0);

            return redirect()->route('dashboard')->with('success', 'Режим «Все компании»: общий отчёт по обеим фирмам.');
        }

        abort_unless(
            $request->user()->companies()->where('companies.id', $id)->where('is_active', true)->exists(),
            403,
            'Вы не привязаны к этой компании.'
        );

        CurrentCompany::set($id);

        return redirect()->route('dashboard')->with('success', 'Компания переключена.');
    }
}
