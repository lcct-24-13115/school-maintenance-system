<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MaintenanceBoardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RepairRequestController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Controllers\TicketController;
use App\Http\Middleware\CheckRole;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    // Sends the user to their own role dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/requester/dashboard', [DashboardController::class, 'requester'])
        // Dept. Heads and the Treasurer land here too (User::dashboardRoute). Before Phase 2 they got a 403.
        ->middleware(CheckRole::class.':'.Role::REQUESTER.','.Role::DEPARTMENT_HEAD.','.Role::TREASURER)->name('requester.dashboard');

    Route::get('/maintenance/dashboard', [DashboardController::class, 'maintenance'])
        ->middleware(CheckRole::class.':'.Role::MAINTENANCE_ADMIN)->name('maintenance.dashboard');

    Route::get('/finance/dashboard', [DashboardController::class, 'finance'])
        ->middleware(CheckRole::class.':'.Role::FINANCE)->name('finance.dashboard');

    Route::get('/custodian/dashboard', [DashboardController::class, 'custodian'])
        ->middleware(CheckRole::class.':'.Role::CUSTODIAN)->name('custodian.dashboard');

    Route::get('/president/dashboard', [DashboardController::class, 'president'])
        ->middleware(CheckRole::class.':'.Role::PRESIDENT)->name('president.dashboard');

    // ---- Notifications (bell, pop-up + chime polling) ----
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/poll', [NotificationController::class, 'poll'])->name('notifications.poll');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // ---- Form 3: Request for Repairs & Improvement (FO-08) -> files a maintenance ticket ----
    Route::get('/repairs/create', [RepairRequestController::class, 'create'])->name('repairs.create');
    Route::post('/repairs', [RepairRequestController::class, 'store'])->name('repairs.store');

    // ---- Ticket list + detail (TicketPolicy / visibleTo decide what each user sees; hidden = 404) ----
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    // Alias of /repairs/create. MUST stay above /tickets/{ticket}, or "create" would be looked up as a ticket.
    Route::get('/tickets/create', [RepairRequestController::class, 'create'])->name('tickets.create');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store'])->name('tickets.attachments.store');

    // ---- Attachments: private files, opened only through here (ticket visibility decides; hidden = 404) ----
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    // ---- Maintenance card board ----
    Route::middleware(CheckRole::class.':'.Role::MAINTENANCE_ADMIN)->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('/board', [MaintenanceBoardController::class, 'index'])->name('board');
        Route::get('/board/cards', [MaintenanceBoardController::class, 'cards'])->name('board.cards');
        Route::post('/tickets/{ticket}/accept', [MaintenanceBoardController::class, 'accept'])->name('accept');
        Route::post('/tickets/{ticket}/complete', [MaintenanceBoardController::class, 'complete'])->name('complete');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
