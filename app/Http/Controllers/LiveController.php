<?php

namespace App\Http\Controllers;

use App\Services\LiveSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase 15: tiny "did anything change?" endpoint for the auto-refresh script (partials/live-refresh). */
class LiveController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $sig = LiveSignature::for($request->user(), max(0, (int) $request->query('t', 0)));

        return response()->json(['sig' => $sig])->header('Cache-Control', 'no-store');
    }
}
