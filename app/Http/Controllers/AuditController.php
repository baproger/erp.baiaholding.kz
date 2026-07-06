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

        // Replace raw foreign-key ids (e.g. «2 → 3») with human-readable names.
        $logs->setCollection(\App\Support\AuditFormatter::humanize($logs->getCollection(), [
            'deal_stage_id' => \App\Models\DealStage::pluck('name', 'id'),
            'project_stage_id' => \App\Models\ProjectStage::pluck('name', 'id'),
            'responsible_user_id' => \App\Models\User::pluck('name', 'id'),
            'assignee_id' => \App\Models\User::pluck('name', 'id'),
            'department_id' => \App\Models\Department::pluck('name', 'id'),
            'client_id' => \App\Models\Client::pluck('name', 'id'),
        ]));

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $request->only('table', 'action'),
            'tables' => AuditLog::query()->distinct()->orderBy('table_name')->pluck('table_name'),
        ]);
    }
}
