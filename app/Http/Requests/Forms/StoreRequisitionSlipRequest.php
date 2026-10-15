<?php

namespace App\Http\Requests\Forms;

use App\Models\RequisitionSlip;
use App\Rules\AdvanceNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Form 2: Requisition Slip, Form 1-08 (3 working days in advance) */
class StoreRequisitionSlipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $types = array_keys(RequisitionSlip::TYPES);

        return [
            'department_id' => ['required', 'exists:departments,id'],
            'date_needed' => ['required', 'date', new AdvanceNotice('requisition')],
            'request_types' => ['required', 'array', 'min:1'],
            'request_types.*' => ['string', Rule::in($types)],
            'other_type' => [
                Rule::requiredIf(fn () => in_array('others', (array) $this->input('request_types', []), true)),
                'nullable', 'string', 'max:255',
            ],
            'payee_name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.account_charged' => ['nullable', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'request_types.required' => 'Please check what you are requesting (Cash, Supplies, ...).',
            'items.required' => 'Add at least one item to the table.',
        ];
    }
}
