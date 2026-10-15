<?php

namespace App\Http\Requests\Forms;

use App\Models\RepairRequest;
use App\Rules\AdvanceNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Form 3: Request for Repairs & Improvement, FO-08 (5 working days in advance) */
class StoreRepairRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $bypass = config('school.emergency_bypasses_advance_notice')
            && $this->input('priority') === RepairRequest::PRIORITY_EMERGENCY;

        return [
            'department_id' => ['required', 'exists:departments,id'],
            'location' => ['required', 'string', 'max:255'],
            'job_description' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:2000'],
            'priority' => ['required', Rule::in([RepairRequest::PRIORITY_FCFS, RepairRequest::PRIORITY_EMERGENCY])],
            'needed_by' => $bypass
                ? ['nullable', 'date']
                : ['required', 'date', new AdvanceNotice('repair')],
            'photo' => ['nullable', 'image', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'location.required' => 'Please tell us where the problem is (building / room).',
            'needed_by.required' => 'Please pick the date you need this done.',
        ];
    }
}
