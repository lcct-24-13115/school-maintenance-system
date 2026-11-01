<?php

namespace App\Http\Requests\Forms;

use App\Models\MaintenanceRequest;
use App\Models\TicketReply;
use App\Rules\ValidAttachment;
use App\Services\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;

/** Phase 10: a message (and files) in the conversation on a ticket page. Errors go to the "thread" bag (the "reply" bag already belongs to the Phase 9 answer form). */
class StoreTicketReplyRequest extends FormRequest
{
    protected $errorBag = 'thread';

    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof MaintenanceRequest || ! ($this->user()?->can('reply', $ticket) ?? false)) {
            return false;
        }

        // Phase 11: an internal note needs the staff permission (a requester who posts the flag gets a 403)
        return ! $this->boolean('is_internal') || $this->user()->can('addInternalNote', $ticket);
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.TicketReply::BODY_MAX],
            'is_internal' => ['nullable', 'boolean'],
            'files' => ['nullable', 'array', 'max:'.AttachmentService::maxFiles()],
            'files.*' => [new ValidAttachment],
        ];
    }

    public function messages(): array
    {
        return [
            'body.max' => 'A reply can be at most '.TicketReply::BODY_MAX.' characters.',
            'files.max' => 'You can upload at most '.AttachmentService::maxFiles().' files at a time.',
        ];
    }
}
