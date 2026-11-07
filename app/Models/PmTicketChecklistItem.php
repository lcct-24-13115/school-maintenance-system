<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the checklist of a PM ticket: a copy of the schedule's template, ticked off by the technician. */
class PmTicketChecklistItem extends Model
{
    protected $fillable = ['request_id', 'label', 'sort_order', 'is_done', 'note'];

    protected function casts(): array
    {
        return ['is_done' => 'boolean'];
    }
}