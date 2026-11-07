<?php

namespace App\Models;

use App\Support\FaqText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * One question and answer of the Help & FAQ page.
 *
 *  - audience  ["all"] or role slugs. A signed-in person sees an entry when it is for "all" or lists their role.
 *  - is_public a visitor who is NOT signed in may read it. Only an "all" entry can be public.
 *  - source    "starter" (made by faq:seed) or "custom" (written or edited by an admin). faq:seed only touches starter rows.
 *  - Only published entries are ever shown to readers. The FAQ manager (Phase 3) sees the rest.
 */
class Faq extends Model
{
    public const ALL = 'all';

    /** Made by faq:seed from database/data/faq_starter.php. faq:seed may update it, until an admin edits it. */
    public const SOURCE_STARTER = 'starter';

    /** Written by an admin, or a starter entry an admin has edited. faq:seed never touches it. */
    public const SOURCE_CUSTOM = 'custom';

    /**
     * Changing one of these on a starter entry turns it into a custom one (see booted()), so the next faq:seed can
     * never overwrite the admin's work. Hiding / showing an entry and moving it up or down are NOT content changes:
     * faq:seed does not touch is_published or sort_order of an existing row anyway.
     */
    public const CONTENT_FIELDS = ['topic', 'audience', 'is_public', 'question', 'answer'];

    protected $fillable = [
        'topic', 'audience', 'is_public', 'question', 'answer', 'source', 'sort_order', 'is_published', 'created_by', 'updated_by',
    ];

    /** A row made by hand is custom unless the seeder says otherwise. */
    protected $attributes = [
        'source' => self::SOURCE_CUSTOM,
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'is_public' => 'boolean',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The safety net for "never overwrite an entry an admin edited": whoever edits the content of a starter
        // entry (the FAQ manager, tinker, a test), it becomes custom in the same save. The seeder itself saves
        // quietly (no events), so its own updates never trigger this.
        static::updating(function (Faq $faq) {
            if ($faq->source === self::SOURCE_STARTER
                && ! $faq->isDirty('source')
                && $faq->isDirty(self::CONTENT_FIELDS)) {
                $faq->source = self::SOURCE_CUSTOM;
            }
        });
    }

    /** The role slugs an entry may be aimed at: every role that has a row in config/permissions.php. @return list<string> */
    public static function roleSlugs(): array
    {
        return array_values(array_map('strval', array_keys((array) config('permissions', []))));
    }

    // ---------- scopes ----------

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** Entries a visitor who is not signed in may read (the audience is checked in isVisibleTo()). */
    public function scopePublicOnly(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeStarter(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_STARTER);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    // ---------- who sees it ----------

    /** @return list<string> the audience as a clean list; empty or missing means everyone */
    public function audienceList(): array
    {
        $list = array_values(array_unique(array_filter(
            array_map('strval', (array) $this->audience),
            fn (string $v) => $v !== ''
        )));

        return $list === [] ? [self::ALL] : $list;
    }

    public function isForEveryone(): bool
    {
        return in_array(self::ALL, $this->audienceList(), true);
    }

    /** May this person (null = a visitor who is not signed in) read this entry? Unpublished entries are never visible. */
    public function isVisibleTo(?User $user): bool
    {
        if (! $this->is_published) {
            return false;
        }

        if ($user === null) {
            return $this->is_public && $this->isForEveryone();
        }

        return $this->isForEveryone() || in_array((string) $user->role?->slug, $this->audienceList(), true);
    }

    /** What this person may read, in display order. The table is small, so the audience is filtered in PHP (same on SQLite and MySQL). @return Collection<int, Faq> */
    public static function visibleTo(?User $user): Collection
    {
        return \App\Support\FaqCache::visible($user);   // Phase 5C: cached 10 minutes per audience, cleared on any admin change
    }

    /** The real query behind visibleTo(). Only FaqCache calls it (on a cache miss). @return Collection<int, Faq> */
    public static function visibleToFresh(?User $user): Collection
    {
        return static::query()->published()->when($user === null, fn ($q) => $q->publicOnly())->ordered()->get()
            ->filter(fn (Faq $faq) => $faq->isVisibleTo($user))
            ->values();
    }

    // ---------- answer ----------

    /** The answer as safe HTML (see FaqText). Use it with {{ $faq->answer_html }}: it is already escaped. */
    protected function answerHtml(): Attribute
    {
        return Attribute::get(fn () => new HtmlString(FaqText::toHtml((string) $this->answer)));
    }

    // ---------- relations ----------

    /** "Was this helpful?" votes (Phase 5A). */
    public function feedback(): HasMany
    {
        return $this->hasMany(FaqFeedback::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}