<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MaintenanceBoardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RepairRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TicketActionController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketReplyController;
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
    // Phase 14: finished tickets (Closed / Cancelled / Disapproved). MUST stay above /tickets/{ticket}.
    Route::get('/tickets/history', [TicketController::class, 'history'])->name('tickets.history');
    // Alias of /repairs/create. MUST stay above /tickets/{ticket}, or "create" would be looked up as a ticket.
    Route::get('/tickets/create', [RepairRequestController::class, 'create'])->name('tickets.create');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store'])->name('tickets.attachments.store');
    // Phase 10: the conversation on the ticket page (text + files)
    Route::post('/tickets/{ticket}/replies', [TicketReplyController::class, 'store'])->middleware('throttle:30,1')->name('tickets.replies.store');

    // ---- Ticket actions (Phase 4A). {ticket} hidden = 404; no capability = 403; wrong status = flash error ----
    Route::prefix('tickets/{ticket}')->name('tickets.')->group(function () {
        Route::post('/send-for-authorization', [TicketActionController::class, 'sendForAuthorization'])->name('send-for-authorization');
        Route::post('/approve', [TicketActionController::class, 'approve'])->name('approve');
        Route::post('/disapprove', [TicketActionController::class, 'disapprove'])->name('disapprove');
        Route::post('/assign', [TicketActionController::class, 'assign'])->name('assign');
        Route::post('/emergency', [TicketActionController::class, 'flagEmergency'])->name('emergency.flag');
        Route::post('/emergency/clear', [TicketActionController::class, 'clearEmergency'])->name('emergency.clear');
        Route::post('/notes', [TicketActionController::class, 'note'])->name('notes.store');
        // Phase 8: the requester's own actions
        Route::post('/cancel', [TicketActionController::class, 'cancel'])->name('cancel');
        Route::post('/confirm-resolved', [TicketActionController::class, 'confirmResolved'])->name('confirm-resolved');
        Route::post('/reopen', [TicketActionController::class, 'reopen'])->name('reopen');
        // Phase 9: ask for information / answer
        Route::post('/request-info', [TicketActionController::class, 'requestInfo'])->name('request-info');
        Route::post('/respond-info', [TicketActionController::class, 'respondToInfo'])->name('respond-info');
        // Phase 12: escalate to the authorizer
        Route::post('/escalate', [TicketActionController::class, 'escalate'])->name('escalate');
    });

    // ---- Authorizer inbox (school.workflow.authorizers only; others get 403) ----
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');

    // ---- Attachments: private files, opened only through here (ticket visibility decides; hidden = 404) ----
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    // ---- Maintenance card board ----
    Route::middleware(CheckRole::class.':'.Role::MAINTENANCE_ADMIN)->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('/board', [MaintenanceBoardController::class, 'index'])->name('board');
        Route::get('/board/cards', [MaintenanceBoardController::class, 'cards'])->name('board.cards');
        Route::post('/tickets/{ticket}/accept', [MaintenanceBoardController::class, 'accept'])->name('accept');
        Route::post('/tickets/{ticket}/complete', [MaintenanceBoardController::class, 'complete'])->name('complete');
    });

    // ---- Admin settings (Merge Phase M1). Capability manage_settings is checked in the controller (others get 403) ----
    Route::get('/admin/settings', [SettingsController::class, 'edit'])->name('admin.settings.edit');
    Route::put('/admin/settings', [SettingsController::class, 'update'])->name('admin.settings.update');

    // ---- Audit trail (Merge Phase M3). Read-only. Capability view_audit_logs is checked in the controller (others get 403) ----
    // ---- Reports (Merge Phase M4). view_analytics opens the page, export_reports the CSV; both checked in the controller (others get 403) ----
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

    Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');

    // ---- Categories admin (Phase 5). Capability manage_categories is checked in the controller (others get 403) ----
    Route::prefix('admin/categories')->name('admin.categories.')->group(function () {
        Route::get('/', [CategoryController::class, 'index'])->name('index');
        Route::post('/', [CategoryController::class, 'store'])->name('store');
        Route::put('/{category}', [CategoryController::class, 'update'])->name('update');
        Route::patch('/{category}/visibility', [CategoryController::class, 'toggle'])->name('toggle');
        Route::post('/{category}/move', [CategoryController::class, 'move'])->name('move');
        Route::delete('/{category}', [CategoryController::class, 'destroy'])->name('destroy');
    });

    // ---- User / team management (Phase 6). Capability manage_users is checked in the controller (others get 403) ----
    Route::prefix('admin/users')->name('admin.users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::patch('/{user}/status', [UserController::class, 'status'])->name('status');
        Route::post('/{user}/reset-password', [UserController::class, 'resetPassword'])->name('reset-password');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ---- Profile photo (Phase 4). Private storage; the image is only reachable through avatars.show ----
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
    Route::get('/avatars/{user}', [ProfileController::class, 'avatar'])->name('avatars.show');
});

require __DIR__.'/auth.php';
