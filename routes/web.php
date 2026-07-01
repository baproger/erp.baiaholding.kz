<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard', [
        'stats' => [
            'deals' => \App\Models\Deal::count(),
            'deals_active' => \App\Models\Deal::where('status', 'active')->count(),
            'deals_budget' => (float) \App\Models\Deal::whereIn('status', ['active', 'draft'])->sum('budget'),
            'projects' => \App\Models\Project::count(),
            'clients' => \App\Models\Client::count(),
            'tasks_open' => \App\Models\Task::where('status', '!=', 'done')->count(),
        ],
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Reference data
    Route::resource('departments', DepartmentController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::resource('clients', ClientController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::resource('products', ProductController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    // Deals
    Route::resource('deals', DealController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::patch('deals/{deal}/stage', [DealController::class, 'updateStage'])->name('deals.stage');

    // Projects
    Route::resource('projects', ProjectController::class)->only(['index', 'show']);
    Route::patch('projects/{project}/stage', [ProjectController::class, 'updateStage'])->name('projects.stage');
});

require __DIR__.'/auth.php';
