<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityRequestItem extends Model
{
    protected $table = 'fe_request_items';

    protected $fillable = ['fe_request_id', 'quantity', 'description'];
}
