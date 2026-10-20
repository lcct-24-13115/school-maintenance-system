<?php

namespace App\Http\Requests\Forms;

use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Rules\ValidAttachment;
use App\Services\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add files to an existing ticket (ticket detail page). */
class StoreTicketAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof MaintenanceRequest && ($this->user()?->can('addAttachment', $ticket) ?? false);
    }

    /** Requesters only add ordinary request files; staff who do the work may also add signed evidence / work photos. */
    public function allowedKinds(): array
    {
        return $this->user()?->can_do('work')
            ? RequestAttachment::KINDS
            : [RequestAttachment::KIND_REQUEST];
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.AttachmentService::maxFiles()],
            'files.*' => [new ValidAttachment],
            'kind' => ['nullable', Rule::in($this->allowedKinds())],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => 'Please choose at least one file.',
            'files.min' => 'Please choose at least one file.',
            'files.max' => 'You can upload at most '.AttachmentService::maxFiles().' files at a time.',
            'kind.in' => 'You cannot add that kind of file to this ticket.',
        ];
    }
}
