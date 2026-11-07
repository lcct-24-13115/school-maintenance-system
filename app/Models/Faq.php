<?php

namespace App\Models;

use App\Support\FaqText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * One question and answer of the Help & FAQ page.
 *
 *  - audience  ["all"] or role slugs. A signed-in person sees an entry when it is for "all" or lists their role.
 *  - is_public a visitor who is NOT signed in may read it. Only an "all" entry can be public.
 *  - Only published entries are ever shown to readers. The FAQ manager (Phase 3) sees the rest.
 */
class Faq extends Model
{
    public const ALL = 'all';

    protected $fillable = [
        'topic', 'audience', 'is_public', 'question', 'answer', 'sort_order', 'is_published', 'created_by', 'updated_by',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
