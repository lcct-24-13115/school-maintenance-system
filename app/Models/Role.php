<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const REQUESTER = 'requester';

    public const MAINTENANCE_ADMIN = 'maintenance_admin';

    public const FINANCE = 'finance';

    public const CUSTODIAN = 'custodian';

    public const PRESIDENT = 'president';

    public const DEPARTMENT_HEAD = 'department_head';   // OIC / Unit Head / Dept. Head

    public const TREASURER = 'treasurer';

    protected $fillable = ['name', 'slug', 'description'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
