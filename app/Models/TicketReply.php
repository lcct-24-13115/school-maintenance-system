<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 10: one message in the conversation on a ticket page. Who may read it is who may see the ticket
 * (TicketPolicy@view); who may write one is TicketPolicy@reply. Its files are request_attachments rows
 * with reply_id set (kind "reply"), so they are served by the same authorized route as every other file.
 */
class TicketReply extends Model
{
    public const BODY_MAX = 2000;

    protected $fillable = ['request_id', 'user_id', 'body'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'request_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RequestAttachment::class, 'reply_id')->oldest('id');
    }
}
