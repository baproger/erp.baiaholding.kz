<?php

use App\Http\Controllers\CommentController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\CustomFieldValueController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StageController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/dashboard', function (\Illuminate\Http\Request $request) {
    $u = $request->user();
    // Dashboard is leadership-only; managers/employees are sent to their working area.
    if (! $u->hasAnyRole(['admin', 'director', 'financist'])) {
        return redirect()->route($u->hasRole('manager') ? 'deals.index' : 'projects.index');
    }
    $taxRate = ((float) \App\Models\Setting::get('tax_percent', 3)) / 100;
    $bonusRate = ((float) \App\Models\Setting::get('bonus_percent', 10)) / 100;

    // Canonical company figures (shared with Analytics & Finance via PayrollService).
    $fin = app(\App\Services\PayrollService::class)->companyTotals();

    $today = now()->startOfDay();
    $overdueCount = \App\Models\Deal::whereNotNull('deadline')->whereDate('deadline', '<', $today)
        ->whereNotIn('status', ['closed', 'cancelled'])
        ->whereDoesntHave('stage', fn ($s) => $s->where('is_won', true))->count();

    $recent = \App\Models\Deal::with('stage:id,name,color,is_won')
        ->where('status', '!=', 'cancelled')
        ->latest()->limit(8)
        ->get(['id', 'number', 'company_name', 'bin', 'client_name', 'budget', 'deadline', 'deal_stage_id', 'status'])
        ->map(function ($d) use ($taxRate, $today) {
            $overdueDays = ($d->deadline && $d->deadline->startOfDay() < $today && ! optional($d->stage)->is_won && ! in_array($d->status, ['closed', 'cancelled']))
                ? (int) $d->deadline->startOfDay()->diffInDays($today) : 0;

            return [
                'id' => $d->id, 'number' => $d->number, 'company' => $d->company_name, 'bin' => $d->bin,
                'budget' => (float) $d->budget, 'net' => round((float) $d->budget * (1 - $taxRate), 2),
                'deadline' => optional($d->deadline)->toDateString(), 'overdue_days' => $overdueDays,
                'stage' => optional($d->stage)->name, 'color' => optional($d->stage)->color,
            ];
        })->sortByDesc('overdue_days')->values();

    return Inertia::render('Dashboard', [
        'metrics' => [
            'total' => $fin['budget'],
            'net' => $fin['company'],
            'bonus' => $fin['bonus'],
            'overdue' => $overdueCount,
            'taxRate' => $taxRate * 100,
            'bonusRate' => $bonusRate * 100,
        ],
        'recent' => $recent,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    // Single profile page (role-aware card). `update`/`destroy` back the Breeze
    // name/email + password + delete forms; `card.update` saves the card fields.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::put('profile/card/{user}', [ProfileController::class, 'updateCard'])->name('profile.card.update');
    // Every user may set their own avatar; images served (auth-gated) via a route.
    Route::post('profile/avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:20,1')->name('profile.avatar');
    Route::get('profile/avatar/{user}', [ProfileController::class, 'avatarShow'])->name('profile.avatar.show');

    // Users
    Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);

    // Reference data
    Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('clients', ClientController::class)->only(['index', 'store', 'update', 'destroy']);

    // Deals
    Route::get('deals/overdue', [DealController::class, 'overdue'])->name('deals.overdue');
    Route::get('deals/bin-lookup', [DealController::class, 'binLookup'])
        ->middleware('throttle:30,1')
        ->name('deals.binLookup');
    Route::resource('deals', DealController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::patch('deals/{deal}/stage', [DealController::class, 'updateStage'])->name('deals.stage');
    Route::patch('deals/{deal}/advance', [DealController::class, 'advance'])->name('deals.advance');
    Route::post('deals/{deal}/to-workshop', [DealController::class, 'sendToWorkshop'])->name('deals.toWorkshop');
    Route::patch('deals/{deal}/responsible', [DealController::class, 'updateResponsible'])->name('deals.responsible');

    // Projects
    Route::resource('projects', ProjectController::class)->only(['index', 'show']);
    Route::patch('projects/{project}/stage', [ProjectController::class, 'updateStage'])->name('projects.stage');
    Route::patch('projects/{project}/advance', [ProjectController::class, 'advance'])->name('projects.advance');
    Route::post('projects/{project}/to-act', [ProjectController::class, 'sendToAct'])->name('projects.toAct');

    // Tasks (managed inline inside deal/project cards — no standalone board)
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    // Finance
    Route::get('finance', [InvoiceController::class, 'index'])->name('finance.index');
    Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->name('expenses.receipt');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

    // Documents
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    // Notifications
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    // Locale
    Route::patch('locale', [LocaleController::class, 'update'])->name('locale.update');

    // Settings
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('settings/stages', [StageController::class, 'index'])->name('stages.index');
    Route::post('settings/stages', [StageController::class, 'store'])->name('stages.store');
    Route::put('settings/stages/{kind}/{id}', [StageController::class, 'update'])->name('stages.update');
    Route::delete('settings/stages/{kind}/{id}', [StageController::class, 'destroy'])->name('stages.destroy');

    // Custom fields
    // UI translations editor
    Route::get('settings/translations', [\App\Http\Controllers\TranslationController::class, 'index'])->name('translations.index');
    Route::put('settings/translations', [\App\Http\Controllers\TranslationController::class, 'update'])->name('translations.update');
    Route::post('settings/translations', [\App\Http\Controllers\TranslationController::class, 'store'])->name('translations.store');
    Route::delete('settings/translations/{translation}', [\App\Http\Controllers\TranslationController::class, 'destroy'])->name('translations.destroy');

    Route::get('settings/custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
    Route::post('settings/custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
    Route::put('settings/custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
    Route::delete('settings/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');
    Route::post('custom-field-values', [CustomFieldValueController::class, 'sync'])->name('custom-field-values.sync');

    // Chat
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('chat', [ChatController::class, 'store'])->middleware('throttle:30,1')->name('chat.store');
    Route::get('chat/{chat}/messages', [ChatController::class, 'messages'])->name('chat.messages');
    // Message send accepts file uploads — throttle to curb spam / storage exhaustion.
    Route::post('chat/{chat}/messages', [ChatController::class, 'sendMessage'])->middleware('throttle:120,1')->name('chat.send');
    Route::put('chat/{chat}', [ChatController::class, 'update'])->middleware('throttle:30,1')->name('chat.update');
    Route::delete('chat/{chat}', [ChatController::class, 'destroy'])->name('chat.destroy');
    Route::delete('chat/messages/{message}', [ChatController::class, 'destroyMessage'])->name('chat.messages.destroy');
    Route::get('chat/messages/{message}/attachment/{index}', [ChatController::class, 'downloadAttachment'])->name('chat.attachment');
    Route::get('chat/{chat}/attachments', [ChatController::class, 'attachments'])->name('chat.attachments');
    Route::get('chat/{chat}/avatar', [ChatController::class, 'avatar'])->name('chat.avatar');

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    // Audit log
    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

    // Comments
    Route::post('comments', [CommentController::class, 'store'])->name('comments.store');
    Route::put('comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
});

require __DIR__.'/auth.php';
