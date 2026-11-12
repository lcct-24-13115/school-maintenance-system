<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Subcategories admin: add / rename / hide / delete the optional sub-types of a category.
 * Same capability as the categories page: manage_categories (others get 403).
 *
 *  - hidden : not offered on new tickets; old tickets keep showing it.
 *  - delete : tickets that used it keep their category and simply lose the subcategory.
 */
class SubcategoryController extends Controller
{
    public function store(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('subcategories', 'name')->where('category_id', $category->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'name.unique' => 'This category already has a subcategory with that name.',
        ]);

        $sub = $category->subcategories()->create([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'sort_order' => ((int) $category->subcategories()->max('sort_order')) + 1,
        ]);

        $this->audit($request, "Subcategory added: {$category->name} > {$sub->name}", $sub);

        return back()->with('status', "Subcategory \"{$sub->name}\" added to {$category->name}.");
    }

    public function update(Request $request, Subcategory $subcategory): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('subcategories', 'name')->where('category_id', $subcategory->category_id)->ignore($subcategory->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'name.unique' => 'This category already has a subcategory with that name.',
        ]);

        $subcategory->fill(['name' => trim($data['name']), 'description' => $data['description'] ?? null]);
        $changed = array_keys($subcategory->getDirty());
        $subcategory->save();

        if ($changed !== []) {
            $this->audit($request, "Subcategory updated: {$subcategory->name}. Changed: ".implode(', ', $changed), $subcategory);
        }

        return back()->with('status', "Subcategory \"{$subcategory->name}\" saved.");
    }

    /** Hide / show in one click (PATCH /admin/categories/subcategories/{subcategory}/visibility). */
    public function toggle(Request $request, Subcategory $subcategory): RedirectResponse
    {
        $this->authorizeManage($request);

        $subcategory->is_hidden = ! $subcategory->is_hidden;
        $subcategory->save();

        $this->audit($request, ($subcategory->is_hidden ? 'Subcategory hidden: ' : 'Subcategory shown: ').$subcategory->name, $subcategory);

        return back()->with('status', $subcategory->is_hidden
            ? "\"{$subcategory->name}\" is hidden. It is no longer offered on new tickets."
            : "\"{$subcategory->name}\" is visible again.");
    }

    public function destroy(Request $request, Subcategory $subcategory): RedirectResponse
    {
        $this->authorizeManage($request);

        $name = $subcategory->name;
        $parent = $subcategory->category?->name;

        // Plain UPDATE so the tickets keep their history; they keep their category and lose only the subcategory.
        $cleared = $subcategory->requests()->withTrashed()->update(['subcategory_id' => null]);
        $subcategory->delete();

        $this->audit($request, "Subcategory deleted: {$parent} > {$name}. Tickets cleared: {$cleared}", null, ['cleared_tickets' => $cleared]);

        return back()->with('status', "\"{$name}\" deleted.");
    }

    private function audit(Request $request, string $description, ?Subcategory $subject = null, ?array $meta = null): void
    {
        AuditLogger::log(AuditLogger::CATEGORY_CHANGED, $description, $subject, $request->user(), $meta);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can_do('manage_categories'), 403, 'You do not have access to this page.');
    }
}
