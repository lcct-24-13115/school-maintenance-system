<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * A Help page search that returned nothing (Help & FAQ Phase 5B). The FAQ manager lists the top ones so the admin
 * knows what to write next.
 *
 *  - term        lowercased, spaces collapsed, at most 100 characters
 *  - count       how many times it was searched (once per visit: see recordOnce())
 *  - last_seen_at
 *  - NOTHING about who searched (no user, no IP, no session id).
 *  - Terms that look personal are not stored at all (see normalize()).
 */
class FaqSearchMiss extends Model
{
    public const MAX_TERM_LENGTH = 100;

    /** Session key: the terms this visit has already counted, so reloading the page does not inflate the number. */
    private const SESSION_KEY = 'faq_miss_terms';

    protected $fillable = ['term', 'count', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The clean term to store, or null when it should not be stored: too short, or it looks like personal data
     * (an email address, a link, or a long number such as a phone or ID number).
     */
    public static function normalize(string $raw): ?string
    {
        $term = preg_replace('/\s+/u', ' ', $raw);
        $term = mb_strtolower(trim((string) $term));
        $term = trim(mb_substr($term, 0, self::MAX_TERM_LENGTH));

        if (mb_strlen($term) < 2) {
            return null;
        }

        if (str_contains($term, '@') || str_contains($term, '://') || str_contains($term, 'www.')) {
            return null;
        }

        if (preg_match_all('/\d/u', $term) >= 6) {
            return null;
        }

        return $term;
    }

    /** Count this search once per visit (session). Safe to call for every empty search. */
    public static function recordOnce(string $raw, Request $request): void
    {
        $term = self::normalize($raw);

        if ($term === null) {
            return;
        }

        $seen = (array) $request->session()->get(self::SESSION_KEY, []);

        if (in_array($term, $seen, true)) {
            return;
        }

        self::record($term);

        $seen[] = $term;
        $request->session()->put(self::SESSION_KEY, array_slice($seen, -50));
    }

    /** Add one to the term's count (a new term starts at 1). The increment happens in the database, so it cannot lose a count. */
    public static function record(string $term): void
    {
        $now = now();

        if (static::query()->where('term', $term)->increment('count', 1, ['last_seen_at' => $now]) > 0) {
            return;
        }

        try {
            static::create(['term' => $term, 'count' => 1, 'last_seen_at' => $now]);
        } catch (QueryException) {
            // two visitors searched a brand-new term at the same moment: the other one created it first
            static::query()->where('term', $term)->increment('count', 1, ['last_seen_at' => $now]);
        }
    }
}
