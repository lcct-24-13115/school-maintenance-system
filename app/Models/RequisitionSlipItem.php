<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionSlipItem extends Model
{
    protected $fillable = ['requisition_slip_id', 'quantity', 'description', 'account_charged', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
