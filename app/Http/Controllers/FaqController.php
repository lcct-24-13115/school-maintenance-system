<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Help & FAQ page (Phase 2).
 *   GET /help         (help.index)   signed-in people: entries for "all" or their role
 *   GET /help-public  (help.public)  anyone: only entries that are public AND for "all"
 * Visibility is decided by Faq::isVisibleTo(): unpublished entries and entries of another role never get here.
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

        $visible = $this->entries($user, '');                                  // what this person may read at all
        $matching = $q === '' ? $visible : $this->entries($user, $q);          // ... that matches the search

        $counts = $matching->countBy('topic');
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

    /** @return Collection<int, Faq> */
    private function entries(?User $user, string $q): Collection
    {
        return Faq::query()
            ->published()
            ->when($user === null, fn ($query) => $query->publicOnly())
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.self::escapeLike(mb_strtolower($q)).'%';

                $query->where(function ($w) use ($like) {
                    $w->whereRaw("LOWER(question) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("LOWER(answer) LIKE ? ESCAPE '!'", [$like]);
                });
            })
            ->ordered()
            ->get()
            ->filter(fn (Faq $faq) => $faq->isVisibleTo($user))   // audience + published, same rule as Phase 1
            ->values();
    }

    /** So that a typed % or _ is searched as itself ("!" is the escape character in the LIKE above). */
    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}