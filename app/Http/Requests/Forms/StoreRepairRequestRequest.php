<?php

namespace App\Http\Requests\Forms;

use App\Models\MaintenanceRequest;
use App\Rules\AdvanceNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Form 3: Request for Repairs & Improvement, FO-08 (5 working days in advance). Files a maintenance ticket. */
class StoreRepairRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $bypass = config('school.emergency_bypasses_advance_notice')
            && $this->input('priority') === MaintenanceRequest::PRIORITY_EMERGENCY;

        return [
            'department_id' => ['required', 'exists:departments,id'],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('is_active', true)],
            'location' => ['required', 'string', 'max:255'],
            'job_description' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:2000'],
            'priority' => ['required', Rule::in([MaintenanceRequest::PRIORITY_FCFS, MaintenanceRequest::PRIORITY_EMERGENCY])],
            'needed_by' => $bypass
                ? ['nullable', 'date']
                : ['required', 'date', new AdvanceNotice('repair')],
            'photo' => ['nullable', 'image', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Please choose the maintenance category (plumbing, electrical...).',
            'location.required' => 'Please tell us where the problem is (building / room).',
            'needed_by.required' => 'Please pick the date you need this done.',
        ];
    }
}
