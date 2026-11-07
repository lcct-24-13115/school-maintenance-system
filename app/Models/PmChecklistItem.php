<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the checklist template of a PM schedule (e.g. "Clean the filter"). */
class PmChecklistItem extends Model
{
    protected $fillable = ['pm_schedule_id', 'label', 'sort_order'];
}