<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class NotificationController extends Controller
{
    /** Full list page (the bell links here). */
    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => $request->user()->notifications()->latest()->paginate(20),
        ]);
    }

    /**
     * Called every few seconds by resources/js/alerts.js.
     * Returns notifications created since the last poll so the browser can chime + pop up.
     * Works with NO WebSocket server; Reverb/Pusher just makes it instant.
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = now();
        $items = [];

        if ($request->filled('since')) {
            try {
                $since = Carbon::parse($request->query('since'))->setTimezone(config('app.timezone'));
                $items = $user->notifications()
                    ->where('created_at', '>=', $since)
                    ->oldest()->limit(20)->get()
                    ->map(fn ($n) => ['id' => $n->id, 'created_at' => $n->created_at->toIso8601String()] + $n->data)
                    ->all();
            } catch (\Throwable) {
                $items = [];
            }
        }

        return response()->json([
            'server_time' => $now->toIso8601String(),
            'unread_count' => $user->unreadNotifications()->count(),
            'items' => $items,
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->first()?->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
