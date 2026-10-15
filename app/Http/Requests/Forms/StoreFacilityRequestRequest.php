<?php

namespace App\Http\Requests\Forms;

use App\Models\Facility;
use App\Rules\AdvanceNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Form 1: Use of Facilities & Equipment (3 days in advance) */
class StoreFacilityRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $othersTicked = Facility::where('name', 'Others')
            ->whereIn('id', (array) $this->input('facility_ids', []))->exists();

        return [
            'department_id' => ['required', 'exists:departments,id'],
            'purpose' => ['required', 'string', 'max:255'],
            'persons_count' => ['required', 'integer', 'min:1', 'max:5000'],
            'date_needed' => ['required', 'date', new AdvanceNotice('facility')],
            'time_needed' => ['required', 'date_format:H:i'],
            'facility_ids' => ['required', 'array', 'min:1'],
            'facility_ids.*' => ['integer', Rule::exists('facilities', 'id')->where('is_active', true)],
            'other_facility' => [Rule::requiredIf($othersTicked), 'nullable', 'string', 'max:255'],
            'items' => ['nullable', 'array', 'max:30'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.description' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'facility_ids.required' => 'Please tick at least one facility.',
            'other_facility.required_if' => 'Please write which facility you mean for "Others".',
        ];
    }
}
