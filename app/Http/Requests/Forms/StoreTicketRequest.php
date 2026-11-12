<?php

namespace App\Http\Requests\Forms;

use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Rules\ValidAttachment;
use App\Services\AttachmentService;
use App\Services\TicketRouter;
use App\Services\TicketRoutingException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Multi-department Phase 3: the generic ticket form (/tickets/create).
 *
 * `assigned_department_id` is the RECEIVING department the person picked. It is only a cross-check:
 * TicketRouter takes the real department from the category and refuses a mismatch.
 */
class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MaintenanceRequest::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subcategory_id' => $this->filled('subcategory_id') ? $this->input('subcategory_id') : null,
            'contact_name' => $this->filled('contact_name') ? trim((string) $this->input('contact_name')) : null,
            'contact_email' => $this->filled('contact_email') ? trim((string) $this->input('contact_email')) : null,
            'contact_phone' => $this->filled('contact_phone') ? trim((string) $this->input('contact_phone')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
            'assigned_department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)->where('is_service_department', true)],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_hidden', false)],
            'subcategory_id' => ['nullable', 'integer', Rule::exists('subcategories', 'id')->where('is_hidden', false)],
            'priority' => ['required', Rule::in([MaintenanceRequest::PRIORITY_FCFS, MaintenanceRequest::PRIORITY_EMERGENCY])],

            // An account without a department (e.g. the admin or the president) says which office it files from.
            'from_department_id' => [Rule::requiredIf(fn () => $this->user()?->department_id === null), 'nullable', 'integer', 'exists:departments,id'],

            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-.\s]{6,30}$/'],

            'files' => ['nullable', 'array', 'max:'.AttachmentService::maxFiles()],
            'files.*' => [new ValidAttachment],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Please give your ticket a short title.',
            'description.required' => 'Please describe what you need.',
            'assigned_department_id.required' => 'Please choose the department that should receive this ticket.',
            'assigned_department_id.exists' => 'That department is not receiving tickets.',
            'category_id.required' => 'Please choose a category.',
            'category_id.exists' => 'Please choose a category that is available.',
            'subcategory_id.exists' => 'Please choose a subcategory that is available.',
            'from_department_id.required' => 'Please choose the department or office you are filing from.',
            'contact_phone.regex' => 'Please enter a valid phone number (digits, spaces, + ( ) - only).',
            'files.max' => 'You can attach at most '.AttachmentService::maxFiles().' files at a time.',
        ];
    }

    /** The checks that need the database: Maintenance goes through FO-08, and the category must agree with the department. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! config('school.routing.allow_maintenance_on_generic_form', false)
                && (int) $this->input('assigned_department_id') === (int) Department::maintenanceId()) {
                $validator->errors()->add('assigned_department_id', 'Maintenance requests (repairs, materials, equipment, supplies) are filed on the repair request form (FO-08).');

                return;
            }

            try {
                TicketRouter::route(
                    (int) $this->input('category_id'),
                    (int) $this->input('assigned_department_id'),
                    $this->filled('subcategory_id') ? (int) $this->input('subcategory_id') : null,
                );
            } catch (TicketRoutingException $e) {
                $validator->errors()->add($e->field, $e->getMessage());
            }
        }];
    }
}
