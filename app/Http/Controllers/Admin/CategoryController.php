<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Categories admin (Phase 5): add / edit / hide / reorder / delete. Capability: manage_categories (others get 403).
 *
 *  - hidden  : not offered on new tickets; old tickets keep showing it.
 *  - locked  : cannot be deleted, renamed or retyped (the "Other" categories are locked).
 *  - delete  : its tickets move to "Other" of the same type.
 */
class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeManage($request);

        $categories = Category::query()
            ->withCount('requests')
            ->with(['subcategories' => fn ($q) => $q->withCount('requests')->ordered()])
            ->ordered()
            ->get()
            ->groupBy('type');

        return view('admin.categories', [
            'types' => Category::typeLabels(),
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Category::typeLabels()))],
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->where('type', $request->input('type'))],
            'description' => ['nullable', 'string', 'max:500'],
            'is_hidden' => ['nullable', 'boolean'],
            'is_locked' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'This type already has a category with that name.',
        ]);

        $category = Category::create([
            'name' => trim($data['name']),
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'is_hidden' => $request->boolean('is_hidden'),
            'is_locked' => $request->boolean('is_locked'),
            'sort_order' => ((int) Category::where('type', $data['type'])->max('sort_order')) + 1,
        ]);

        $this->audit($request, "Category added: {$category->name} ({$category->type})", $category);

        return back()->with('status', "Category \"{$category->name}\" added.");
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->where('type', $request->input('type', $category->type))->ignore($category->id)],
            'type' => ['required', Rule::in(array_keys(Category::typeLabels()))],
            'description' => ['nullable', 'string', 'max:500'],
            'is_hidden' => ['nullable', 'boolean'],
            'is_locked' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'This type already has a category with that name.',
        ]);

        // A locked category keeps its name and type. Only its description, visibility and lock can change.
        $name = $category->is_locked ? $category->name : trim($data['name']);
        $type = $category->is_locked ? $category->type : $data['type'];

        if ($type !== $category->type && $category->requests()->withTrashed()->exists()) {
            return back()->withInput()->withErrors([
                'type' => 'Tickets already use this category, so its type cannot change. Create a new category instead.',
            ]);
        }

        $before = $category->only(['name', 'type', 'is_hidden', 'is_locked']);

        $category->fill([
            'name' => $name,
            'type' => $type,
            'description' => $data['description'] ?? null,
            'is_hidden' => $request->boolean('is_hidden'),
            'is_locked' => $request->boolean('is_locked'),
        ]);

        if ($category->isDirty('type')) {
            $category->sort_order = ((int) Category::where('type', $type)->max('sort_order')) + 1;
        }

        $changed = array_keys($category->getDirty());
        $category->save();

        if ($changed !== []) {
            $this->audit($request, "Category updated: {$category->name}. Changed: ".implode(', ', $changed), $category, ['before' => $before]);
        }

        return back()->with('status', "Category \"{$category->name}\" saved.");
    }

    /** Hide / show in one click (PATCH /admin/categories/{category}/visibility). */
    public function toggle(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeManage($request);

        $category->is_hidden = ! $category->is_hidden;
        $category->save();

        $this->audit($request, ($category->is_hidden ? 'Category hidden: ' : 'Category shown: ').$category->name, $category);

        return back()->with('status', $category->is_hidden
            ? "\"{$category->name}\" is hidden. It is no longer offered on new tickets."
            : "\"{$category->name}\" is visible again.");
    }

    /** Move one step up or down inside its type (POST /admin/categories/{category}/move, direction=up|down). */
    public function move(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeManage($request);

        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        $ids = array_map('intval', Category::where('type', $category->type)->ordered()->orderBy('id')->pluck('id')->all());
        $i = array_search((int) $category->id, $ids, true);
        $j = $direction === 'up' ? $i - 1 : $i + 1;

        if ($i !== false && isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];

            // write the whole order again, so equal or missing sort_order values can never get stuck
            foreach ($ids as $position => $id) {
                Category::whereKey($id)->update(['sort_order' => $position + 1]);
            }

            $this->audit($request, "Category reordered: {$category->name} moved {$direction}", $category);
        }

        return back();
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeManage($request);

        $name = $category->name;
        $type = $category->type;

        try {
            $moved = $category->deleteMovingTicketsToOther();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit($request, "Category deleted: {$name} ({$type}). Tickets moved to Other: {$moved}", null, ['moved_tickets' => $moved]);

        return back()->with('status', $moved > 0
            ? "\"{$name}\" deleted. {$moved} ticket(s) moved to Other."
            : "\"{$name}\" deleted.");
    }

    private function audit(Request $request, string $description, ?Category $subject = null, ?array $meta = null): void
    {
        AuditLogger::log(AuditLogger::CATEGORY_CHANGED, $description, $subject, $request->user(), $meta);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can_do('manage_categories'), 403, 'You do not have access to this page.');
    }
}
