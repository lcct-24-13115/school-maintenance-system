<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\FaqFeedback;
use App\Models\FaqSearchMiss;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Help & FAQ page (Phase 2).
 *   GET /help         (help.index)   signed-in people: entries for "all" or their role
 *   GET /help-public  (help.public)  anyone: only entries that are public AND for "all"  (throttle:60,1)
 * Visibility is decided by Faq::isVisibleTo(): unpublished entries and entries of another role never get here.
 *
 * Phase 5C: the list a person may read comes from FaqCache (10 minutes, one copy per audience, cleared on any admin
 * change). The search and the topic counts are done on that list, so a visit costs one query on the faqs table
 * when the copy is cold and none when it is warm: the counts of all topics come from the same list, not from a
 * query per topic.
 */
class FaqController extends Controller
{
    public const MAX_QUERY_LENGTH = 100;

    public function index(Request $request): View|RedirectResponse
    {
        $publicPage = $request->routeIs('help.public');

        // A signed-in person has no use for the visitor page: send them to the real one (keeping the search).
        if ($publicPage && $request->user()) {
            return redirect()->route('help.index', $request->query());
        }

        $user = $publicPage ? null : $request->user();
        $q = self::cleanQuery($request->query('q'));

        $visible = Faq::visibleTo($user);                                      // what this person may read at all (cached)
        $matching = $q === '' ? $visible : self::search($visible, $q);         // ... that matches the search

        // Phase 5B: a search that finds nothing is written down (the words only), so the admin knows what to write next.
        if ($q !== '' && $matching->isEmpty()) {
            FaqSearchMiss::recordOnce($q, $request);
        }

        $counts = $matching->countBy('topic');   // every topic's count in one pass over the list
        $topics = $visible->pluck('topic')->unique()->values()
            ->map(fn (string $name) => ['name' => $name, 'count' => (int) ($counts[$name] ?? 0)]);

        $requested = $request->query('topic');
        $topic = is_string($requested) && $visible->contains('topic', $requested) ? $requested : null;

        $entries = $topic === null ? $matching : $matching->where('topic', $topic)->values();

        return view('help.index', [
            'isGuest' => $user === null,
            'q' => $q,
            'topic' => $topic,
            'topics' => $topics,
            'total' => $matching->count(),
            'entries' => $entries,
            'groups' => $entries->groupBy('topic'),
            'votes' => FaqFeedback::votesFor($entries->pluck('id')->all(), $user, $request),   // Phase 5A: what this reader already voted
        ]);
    }

    /** Trim, collapse spaces, drop control characters, cap at 100 characters. Anything that is not text becomes "". */
    public static function cleanQuery(mixed $raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);
        $clean = $clean === null ? '' : preg_replace('/\s+/u', ' ', $clean);

        return trim(mb_substr(trim((string) $clean), 0, self::MAX_QUERY_LENGTH));
    }

    /**
     * The entries whose question or answer contains the words (not case sensitive; a typed % or _ is searched as itself).
     * It only ever looks inside the list the person may already read.
     *
     * @param  Collection<int, Faq>  $entries
     * @return Collection<int, Faq>
     */
    private static function search(Collection $entries, string $q): Collection
    {
        $needle = mb_strtolower($q);

        return $entries
            ->filter(fn (Faq $faq) => str_contains(mb_strtolower((string) $faq->question), $needle)
                || str_contains(mb_strtolower((string) $faq->answer), $needle))
            ->values();
    }
}