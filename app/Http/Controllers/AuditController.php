<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('setting.viewAny') || $request->user()->hasRole('admin'), 403);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($request->string('table')->toString(), fn ($q, $t) => $q->where('table_name', $t))
            ->when($request->string('action')->toString(), fn ($q, $a) => $q->where('action', $a))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $request->only('table', 'action'),
            'tables' => AuditLog::query()->distinct()->orderBy('table_name')->pluck('table_name'),
        ]);
    }
}
