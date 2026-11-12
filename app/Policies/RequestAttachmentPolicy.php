<?php

namespace App\Policies;

use App\Models\RequestAttachment;
use App\Models\User;

/**
 * Who may REMOVE an uploaded file (Laravel finds this policy by name: RequestAttachment -> RequestAttachmentPolicy).
 *
 *  - The ticket must be visible to the person (a hidden ticket never reveals its files).
 *  - A file inside an INTERNAL note: only people who may read internal notes.
 *  - Anyone with the add_attachment capability (Maintenance Admin) may remove any file of a ticket they see.
 *  - Everyone else may remove only the files THEY uploaded, and only while the ticket is not Closed/Disapproved.
 * CONFIRM WITH CLIENT: who may delete, and whether a Closed ticket's files may ever be removed.
 */
class RequestAttachmentPolicy
{
    public function delete(User $user, RequestAttachment $attachment): bool
    {
        $ticket = $attachment->request;

        if (! $user->is_active || $ticket === null || ! $ticket->isVisibleTo($user)) {
            return false;
        }

        if ($attachment->reply?->is_internal && ! $user->can('viewInternalNotes', $ticket)) {
            return false;
        }

        if ($user->can_do('add_attachment')) {
            return true;
        }

        return $attachment->uploaded_by !== null
            && (int) $attachment->uploaded_by === (int) $user->id
            && ! $ticket->status->isTerminal();
    }
}
