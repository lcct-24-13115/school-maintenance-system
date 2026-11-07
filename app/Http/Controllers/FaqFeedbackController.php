<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\FaqFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Was this helpful?" (Help & FAQ Phase 5A).
 *   POST /help/{faq}/feedback         (help.feedback)         signed-in people
 *   POST /help-public/{faq}/feedback  (help.public.feedback)  visitors who are not signed in
 * Both are throttled (see routes/web.php) and protected by CSRF like every web form.
 *
 *  - Only an entry the voter may READ can be rated (same rule as the Help page: Faq::isVisibleTo()). Anything else
 *    answers 404, so the route never tells whether a hidden or other-role entry exists.
 *  - One vote per person per entry. Voting again changes the vote.
 *  - Works with fetch (JSON answer) and without JavaScript (redirect back to the entry).
 */
class FaqFeedbackController extends Controller
{
    public function store(Request $request, Faq $faq): JsonResponse|RedirectResponse
    {
        // The visitor route is for visitors only, even if a signed-in person posts to it.
        $user = $request->routeIs('help.public.feedback') ? null : $request->user();

        abort_unless($faq->isVisibleTo($user), 404);

        $request->validate(['helpful' => ['required', 'boolean']]);
        $helpful = $request->boolean('helpful');

        if ($user !== null) {
            FaqFeedback::updateOrCreate(
                ['faq_id' => $faq->id, 'user_id' => $user->id],
                ['helpful' => $helpful],
            );
        } else {
            $hash = FaqFeedback::guestHash($request, true);

            $alreadyVoted = FaqFeedback::query()
                ->where('faq_id', $faq->id)->whereNull('user_id')->where('session_hash', $hash)
                ->exists();

            if (! $alreadyVoted && $this->guestVoteCount($hash) >= FaqFeedback::GUEST_MAX_ENTRIES) {
                return $this->refuse($request, $faq, 'You have rated the most answers allowed for one visit. Thank you!', 429);
            }

            FaqFeedback::updateOrCreate(
                ['faq_id' => $faq->id, 'user_id' => null, 'session_hash' => $hash],
                ['helpful' => $helpful, 'ip_hash' => FaqFeedback::ipHash($request->ip())],
            );
        }

        $message = $helpful ? 'You marked this as helpful.' : 'You marked this as not helpful.';

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'helpful' => $helpful, 'message' => $message]);
        }

        return $this->backToEntry($request, $faq)->with('help_feedback_id', $faq->id);
    }

    private function guestVoteCount(?string $hash): int
    {
        return FaqFeedback::query()->whereNull('user_id')->where('session_hash', $hash)->count();
    }

    private function refuse(Request $request, Faq $faq, string $message, int $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $message], $status);
        }

        return $this->backToEntry($request, $faq)->with([
            'help_feedback_id' => $faq->id,
            'help_feedback_error' => $message,
        ]);
    }

    /** Back to the page the person was on (keeping the search), at the entry they rated. */
    private function backToEntry(Request $request, Faq $faq): RedirectResponse
    {
        $fallback = $request->routeIs('help.public.feedback') ? route('help.public') : route('help.index');

        return redirect()->to(Str::before(url()->previous($fallback), '#').'#faq-'.$faq->id);
    }
}
