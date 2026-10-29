<?php

namespace App\Http\Requests\Forms;

use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Rules\AdvanceNotice;
use App\Rules\ValidAttachment;
use App\Services\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Form 3: Request for Repairs & Improvement, FO-08 (5 working days in advance). Files a maintenance ticket. */
class StoreRepairRequestRequest extends FormRequest
{
    public const MAX_ITEMS = 20;

    public function authorize(): bool
    {
        return $this->user()?->can('create', MaintenanceRequest::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $type = $this->filled('request_type') ? (string) $this->input('request_type') : null;

        // Items belong to a request type. The items block is only SHOWN when a type is chosen, but hidden inputs
        // are still sent by the browser, so without a type they are dropped here. Completely empty rows are dropped too.
        $items = [];
        if ($type !== null) {
            foreach ((array) $this->input('items', []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (blank($row['item_name'] ?? null) && blank($row['unit'] ?? null) && blank($row['specification'] ?? null)) {
                    continue;
                }
                $items[] = $row;
            }
        }

        $this->merge([
            'request_type' => $type,
            'items' => $items,
            'is_confidential' => $this->boolean('is_confidential'),
        ]);
    }

    public function rules(): array
    {
        // Only people who may really flag an emergency skip the advance-notice rule.
        // Anyone else who ticks "Emergency" is only ASKING for a review, so the normal date rule applies.
        $bypass = config('school.emergency_bypasses_advance_notice')
            && $this->user()->can_do('flag_emergency')
            && $this->input('priority') === MaintenanceRequest::PRIORITY_EMERGENCY;

        $type = $this->input('request_type');

        return [
            'department_id' => ['required', 'exists:departments,id'],
            'request_type' => ['nullable', Rule::in([Category::TYPE_MATERIALS, Category::TYPE_EQUIPMENT, Category::TYPE_SUPPLIES])],
            // When a request type is chosen the category must be of that type (the model enforces it too).
            'category_id' => ['required', Rule::exists('categories', 'id')->where(function ($query) use ($type) {
                $query->where('is_hidden', false);
                if ($type) {
                    $query->where('type', $type);
                }
            })],
            'location' => ['required', 'string', 'max:255'],
            'job_description' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:2000'],
            'priority' => ['required', Rule::in([MaintenanceRequest::PRIORITY_FCFS, MaintenanceRequest::PRIORITY_EMERGENCY])],
            'needed_by' => $bypass
                ? ['nullable', 'date']
                : ['required', 'date', new AdvanceNotice('repair')],
            'is_confidential' => ['boolean'],

            'items' => ['nullable', 'array', 'max:'.self::MAX_ITEMS],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.specification' => ['nullable', 'string', 'max:1000'],

            'files' => ['nullable', 'array', 'max:'.AttachmentService::maxFiles()],
            'files.*' => [new ValidAttachment],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Please choose the maintenance category (plumbing, electrical...).',
            'category_id.exists' => 'Please choose a category that matches the request type.',
            'request_type.in' => 'Please choose Materials, Equipment, Supplies, or leave the request type empty.',
            'location.required' => 'Please tell us where the problem is (building / room).',
            'needed_by.required' => 'Please pick the date you need this done.',
            'files.max' => 'You can attach at most '.AttachmentService::maxFiles().' files at a time.',
            'items.max' => 'You can list at most '.self::MAX_ITEMS.' items.',
            'items.*.item_name.required' => 'Each item needs a name.',
            'items.*.quantity.required' => 'Each item needs a quantity.',
            'items.*.quantity.integer' => 'The quantity must be a whole number.',
            'items.*.quantity.min' => 'The quantity must be at least 1.',
        ];
    }
}
