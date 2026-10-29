<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\CheckRole;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware('auth')->group(function () {
    // Sends the user to their own role dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/requester/dashboard', [DashboardController::class, 'requester'])
        ->middleware(CheckRole::class.':'.Role::REQUESTER)->name('requester.dashboard');

    Route::get('/maintenance/dashboard', [DashboardController::class, 'maintenance'])
        ->middleware(CheckRole::class.':'.Role::MAINTENANCE_ADMIN)->name('maintenance.dashboard');

    Route::get('/finance/dashboard', [DashboardController::class, 'finance'])
        ->middleware(CheckRole::class.':'.Role::FINANCE)->name('finance.dashboard');

    Route::get('/custodian/dashboard', [DashboardController::class, 'custodian'])
        ->middleware(CheckRole::class.':'.Role::CUSTODIAN)->name('custodian.dashboard');

    Route::get('/president/dashboard', [DashboardController::class, 'president'])
        ->middleware(CheckRole::class.':'.Role::PRESIDENT)->name('president.dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
