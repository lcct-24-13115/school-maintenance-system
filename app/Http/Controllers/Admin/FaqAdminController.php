<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * FAQ manager (Help & FAQ Phase 3): add / edit / order / hide / duplicate / delete the Help page entries.
 * Capability: manage_faq (others get 403). Same behaviour as the Categories admin page.
 *
 *  - audience  ["all"] or role slugs. "Everyone" wins: if it is ticked, the role boxes are ignored.
 *  - is_public ("Show before sign-in") is only kept when the audience is Everyone. Enforced HERE, not only in the form.
 *  - order     sort_order is per topic; move up / down swaps places inside the same topic.
 */
class FaqAdminController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $this->authorizeManage($request);

        $filters = $request->validate([
            'topic' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(['published', 'hidden'])],
            'public' => ['nullable', Rule::in(['yes'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $q = trim((string) ($filters['q'] ?? ''));

        $query = Faq::query()
            ->when($filters['topic'] ?? null, fn ($w, $topic) => $w->where('topic', $topic))
            ->when(($filters['status'] ?? null) === 'published', fn ($w) => $w->where('is_published', true))
            ->when(($filters['status'] ?? null) === 'hidden', fn ($w) => $w->where('is_published', false))
            ->when(($filters['public'] ?? null) === 'yes', fn ($w) => $w->where('is_public', true))
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';
                $w->where(function ($x) use ($like) {
                    $x->whereRaw("LOWER(question) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("LOWER(answer) LIKE ? ESCAPE '!'", [$like]);
                });
            });

        $total = (clone $query)->count();

        // Topics are listed in the order of their first entry, so the page reads like the Help page.
        $faqs = $query->orderBy('topic')->ordered()->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.faq.index', [
            'faqs' => $faqs,
            'total' => $total,
            'filters' => ['topic' => $filters['topic'] ?? '', 'status' => $filters['status'] ?? '', 'public' => $filters['public'] ?? '', 'q' => $q],
            'topics' => $this->topics(),
            'roleLabels' => $this->roleLabels(),
            // the neighbours inside the same topic decide which arrow buttons are disabled (whole topic, not the filtered page)
            'edges' => $this->edges(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeManage($request);

        return view('admin.faq.form', [
            'faq' => new Faq(['audience' => [Faq::ALL], 'is_published' => true, 'is_public' => false]),
            'topics' => $this->topics(),
            'roleLabels' => $this->roleLabels(),
            'nextOrder' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $this->validated($request, null);

        $data['sort_order'] = $data['sort_order'] ?? $this->nextOrder($data['topic']);

        $faq = Faq::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        $this->audit(AuditLogger::FAQ_CREATED, "FAQ added: {$faq->question} (topic: {$faq->topic})", $faq);

        return redirect()->route('admin.faq.index', ['topic' => $faq->topic])
            ->with('status', "FAQ \"{$faq->question}\" added.");
    }

    public function edit(Request $request, Faq $faq): View
    {
        $this->authorizeManage($request);

        return view('admin.faq.form', [
            'faq' => $faq,
            'topics' => $this->topics(),
            'roleLabels' => $this->roleLabels(),
        ]);
    }

    public function update(Request $request, Faq $faq): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $this->validated($request, $faq);

        $wasPublished = $faq->is_published;
        $oldTopic = $faq->topic;

        // an empty "sort order" box means "keep the current order" (the column cannot be empty)
        if ($data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        $faq->fill($data);
        $faq->updated_by = $request->user()->id;

        // moved to another topic without an explicit order: go to the end of that topic
        if ($faq->isDirty('topic') && ! $request->filled('sort_order')) {
            $faq->sort_order = $this->nextOrder($faq->topic);
        }

        $changed = array_values(array_diff(array_keys($faq->getDirty()), ['updated_by']));
        $faq->save();

        if ($changed !== []) {
            $this->audit(AuditLogger::FAQ_UPDATED, "FAQ updated: {$faq->question}. Changed: ".implode(', ', $changed), $faq,
                ['changed' => $changed, 'old_topic' => $oldTopic]);
        }

        if ($wasPublished !== $faq->is_published) {
            $this->auditVisibility($faq);
        }

        return redirect()->route('admin.faq.index', ['topic' => $faq->topic])
            ->with('status', "FAQ \"{$faq->question}\" saved.");
    }

    /** Publish / hide in one click (PATCH /admin/faq/{faq}/visibility). */
    public function toggle(Request $request, Faq $faq): RedirectResponse
    {
        $this->authorizeManage($request);

        $faq->forceFill(['is_published' => ! $faq->is_published, 'updated_by' => $request->user()->id])->save();

        $this->auditVisibility($faq);

        return back()->with('status', $faq->is_published
            ? "\"{$faq->question}\" is published again."
            : "\"{$faq->question}\" is hidden. Nobody sees it on the Help page.");
    }

    /** Move one step up or down inside its topic (POST /admin/faq/{faq}/move, direction=up|down). */
    public function move(Request $request, Faq $faq): RedirectResponse
    {
        $this->authorizeManage($request);

        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        $ids = array_map('intval', Faq::where('topic', $faq->topic)->ordered()->pluck('id')->all());
        $i = array_search((int) $faq->id, $ids, true);
        $j = $direction === 'up' ? $i - 1 : $i + 1;

        if ($i !== false && isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];

            // write the whole order again, so equal or missing sort_order values can never get stuck
            foreach ($ids as $position => $id) {
                Faq::whereKey($id)->update(['sort_order' => ($position + 1) * 10]);
            }

            $this->audit(AuditLogger::FAQ_REORDERED, "FAQ reordered: {$faq->question} moved {$direction} (topic: {$faq->topic})", $faq, ['direction' => $direction]);
        }

        return back();
    }

    /** A copy that starts HIDDEN, at the end of the same topic, so nothing half-finished goes live by accident. */
    public function duplicate(Request $request, Faq $faq): RedirectResponse
    {
        $this->authorizeManage($request);

        $question = $this->copyQuestion($faq);

        $copy = Faq::create([
            'topic' => $faq->topic,
            'audience' => $faq->audienceList(),
            'is_public' => $faq->is_public && $faq->isForEveryone(),
            'question' => $question,
            'answer' => $faq->answer,
            'sort_order' => $this->nextOrder($faq->topic),
            'is_published' => false,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $this->audit(AuditLogger::FAQ_CREATED, "FAQ duplicated: {$copy->question} (copy of #{$faq->id}, topic: {$copy->topic})", $copy, ['copied_from' => $faq->id]);

        return redirect()->route('admin.faq.edit', $copy)
            ->with('status', 'Copy created and hidden. Edit it, then publish it.');
    }

    public function destroy(Request $request, Faq $faq): RedirectResponse
    {
        $this->authorizeManage($request);

        $question = $faq->question;
        $topic = $faq->topic;
        $id = $faq->id;

        $faq->delete();

        $this->audit(AuditLogger::FAQ_DELETED, "FAQ deleted: {$question} (topic: {$topic})", null, ['faq_id' => $id]);

        return back()->with('status', "FAQ \"{$question}\" deleted.");
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> the clean values to save (topic, question, answer, audience, is_public, is_published, sort_order) */
    private function validated(Request $request, ?Faq $faq): array
    {
        $roles = Faq::roleSlugs();

        // "new topic" text wins over the select when it is filled in
        $newTopic = trim((string) $request->input('new_topic', ''));
        $topic = $newTopic !== '' ? $newTopic : trim((string) $request->input('topic', ''));
        $request->merge(['topic' => $topic, 'question' => trim((string) $request->input('question', ''))]);

        $data = $request->validate([
            'topic' => ['required', 'string', 'max:60'],
            'question' => [
                'required', 'string', 'min:5', 'max:255',
                Rule::unique('faqs', 'question')->where('topic', $topic)->ignore($faq?->id),
            ],
            'answer' => ['required', 'string', 'min:5', 'max:5000'],
            'everyone' => ['nullable', 'boolean'],
            'audience' => ['nullable', 'array'],
            'audience.*' => ['string', Rule::in($roles)],
            'is_public' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [
            'topic.required' => 'Pick a topic or type a new one.',
            'question.unique' => 'This topic already has that question.',
            'audience.*.in' => 'Pick only roles from the list.',
        ]);

        $picked = array_values(array_unique(array_map('strval', $data['audience'] ?? [])));

        // Everyone ticked -> ["all"]. Otherwise at least one role is needed.
        if ($request->boolean('everyone')) {
            $audience = [Faq::ALL];
        } elseif ($picked !== []) {
            $audience = $picked;
        } else {
            throw ValidationException::withMessages(['audience' => 'Tick "Everyone" or at least one role.']);
        }

        // Before-sign-in is only for Everyone: asking for it with a role audience is refused, not silently dropped.
        $public = $request->boolean('is_public');
        if ($public && $audience !== [Faq::ALL]) {
            throw ValidationException::withMessages(['is_public' => '"Show before sign-in" only works when the audience is Everyone.']);
        }

        return [
            'topic' => $topic,
            'question' => $data['question'],
            'answer' => $data['answer'],
            'audience' => $audience,
            'is_public' => $public,
            'is_published' => $request->boolean('is_published'),
            'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : null,
        ];
    }

    private function nextOrder(string $topic): int
    {
        return ((int) Faq::where('topic', $topic)->max('sort_order')) + 10;
    }

    /** "Question (copy)", "Question (copy 2)" ... kept inside 255 characters and unique inside the topic. */
    private function copyQuestion(Faq $faq): string
    {
        $base = mb_substr($faq->question, 0, 235);

        for ($n = 1; $n < 100; $n++) {
            $candidate = $base.($n === 1 ? ' (copy)' : " (copy {$n})");
            if (! Faq::where('topic', $faq->topic)->where('question', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $base.' (copy '.now()->timestamp.')';
    }

    /** @return list<string> */
    private function topics(): array
    {
        return Faq::query()->select('topic')->distinct()->orderBy('topic')->pluck('topic')->all();
    }

    /** @return array<string, string> role slug => label */
    private function roleLabels(): array
    {
        return collect(Faq::roleSlugs())->mapWithKeys(fn (string $slug) => [$slug => \Illuminate\Support\Str::headline($slug)])->all();
    }

    /** @return array<string, array{first: int, last: int}> topic => first / last entry id in display order */
    private function edges(): array
    {
        $edges = [];
        foreach (Faq::query()->ordered()->get(['id', 'topic']) as $row) {
            $edges[$row->topic]['first'] ??= $row->id;
            $edges[$row->topic]['last'] = $row->id;
        }

        return $edges;
    }

    private function auditVisibility(Faq $faq): void
    {
        $this->audit(
            $faq->is_published ? AuditLogger::FAQ_PUBLISHED : AuditLogger::FAQ_HIDDEN,
            ($faq->is_published ? 'FAQ published: ' : 'FAQ hidden: ').$faq->question." (topic: {$faq->topic})",
            $faq
        );
    }

    private function audit(string $action, string $description, ?Faq $subject = null, ?array $meta = null): void
    {
        AuditLogger::log($action, $description, $subject, request()->user(), $meta);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->canManageFaq(), 403, 'You do not have access to this page.');
    }
}