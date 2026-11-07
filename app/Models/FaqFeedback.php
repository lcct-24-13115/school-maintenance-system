<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One "Was this helpful?" vote on one Help & FAQ entry (Help & FAQ Phase 5A).
 *
 *  - Signed-in person: one row per entry (faq_id + user_id). Voting again updates the same row.
 *  - Guest: one row per entry per session (faq_id + session_hash). At most GUEST_MAX_ENTRIES different entries per session.
 *  - Nothing here identifies a guest: the session token is hashed and the IP address is a keyed hash.
 */
class FaqFeedback extends Model
{
    protected $table = 'faq_feedback';

    /** Where the random guest token lives in the session. */
    public const GUEST_SESSION_KEY = 'faq_guest_token';

    /** A guest session may rate this many different entries (changing an earlier vote is always allowed). */
    public const GUEST_MAX_ENTRIES = 20;

    protected $fillable = ['faq_id', 'user_id', 'session_hash', 'helpful', 'ip_hash'];

    protected function casts(): array
    {
        return [
            'helpful' => 'boolean',
        ];
    }

    public function faq(): BelongsTo
    {
        return $this->belongsTo(Faq::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The hash that tells one guest session from another. With $create = false it never starts a token
     * (reading the Help page must not write to the session); it then returns null for a guest who never voted.
     */
    public static function guestHash(Request $request, bool $create = false): ?string
    {
        $token = $request->session()->get(self::GUEST_SESSION_KEY);

        if (! is_string($token) || $token === '') {
            if (! $create) {
                return null;
            }

            $token = Str::random(40);
            $request->session()->put(self::GUEST_SESSION_KEY, $token);
        }

        return hash('sha256', $token);
    }

    /** A keyed hash of the IP address (the raw address is never stored). */
    public static function ipHash(?string $ip): ?string
    {
        return $ip === null || $ip === '' ? null : hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /**
     * What this reader has already voted, for the entries on the page.
     *
     * @param  list<int>  $faqIds
     * @return array<int, bool>  faq id => true (helpful) or false (not helpful); entries without a vote are missing
     */
    public static function votesFor(array $faqIds, ?User $user, Request $request): array
    {
        if ($faqIds === []) {
            return [];
        }

        $query = static::query()->whereIn('faq_id', $faqIds);

        if ($user !== null) {
            $query->where('user_id', $user->id);
        } else {
            $hash = self::guestHash($request);

            if ($hash === null) {
                return [];
            }

            $query->whereNull('user_id')->where('session_hash', $hash);
        }

        return $query->pluck('helpful', 'faq_id')->map(fn ($v) => (bool) $v)->all();
    }
}
