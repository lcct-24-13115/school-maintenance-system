<?php

namespace App\Support;

use App\Models\Faq;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cache of the PUBLISHED Help & FAQ list, one copy per audience (Help & FAQ Phase 5C).
 *
 *  - Audience = "guest" (not signed in) or one role. Two people of the same role may read exactly the same entries,
 *    so they share one copy. The key is built here from the person's role, never from anything in the request,
 *    so one audience can never be handed another audience's copy.
 *  - What is stored is only what that audience may read (published, public for guests, audience checked), as plain
 *    rows. Unpublished entries and entries of other roles are never put into a copy.
 *  - A copy lives 10 minutes. Any admin change clears all copies at once (FaqObserver, plus flush() in the two
 *    places that write without model events: the order buttons and faq:seed).
 *  - Clearing = a new "version" token in the cache; old copies are simply never read again and expire by themselves.
 *    (This works on every cache driver, also "database", which has no tags.)
 */
class FaqCache
{
    public const TTL_MINUTES = 10;

    private const VERSION_KEY = 'faq.list.version';

    /** What this person may read, in display order. Same result as Faq::visibleToFresh(), but cached. @return Collection<int, Faq> */
    public static function visible(?User $user): Collection
    {
        try {
            $rows = Cache::remember(
                self::key($user),
                now()->addMinutes(self::TTL_MINUTES),
                fn () => Faq::visibleToFresh($user)->map(fn (Faq $faq) => $faq->getAttributes())->values()->all(),
            );
        } catch (QueryException) {
            return Faq::visibleToFresh($user);   // the cache table is not migrated yet: skip the cache
        }

        return Faq::hydrate($rows);
    }

    /** Throw away every cached list (all audiences). Call it after ANY change to the faqs table. */
    public static function flush(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, (string) Str::uuid());
        } catch (QueryException) {
            // no cache table, so nothing is cached either
        }
    }

    /** "guest", "norole" or "role.<slug>". */
    public static function audienceKey(?User $user): string
    {
        if ($user === null) {
            return 'guest';
        }

        $slug = (string) $user->role?->slug;

        return $slug === '' ? 'norole' : 'role.'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $slug);
    }

    public static function key(?User $user): string
    {
        return 'faq.list.'.self::version().'.'.self::audienceKey($user);
    }

    private static function version(): string
    {
        try {
            return (string) Cache::get(self::VERSION_KEY, '0');
        } catch (QueryException) {
            return '0';
        }
    }
}