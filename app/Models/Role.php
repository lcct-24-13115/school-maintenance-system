<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const REQUESTER = 'requester';

    public const MAINTENANCE_ADMIN = 'maintenance_admin';   // Admin: users, settings, categories, e-mail log + everything the Head does

    public const HEAD = 'head';                              // Head of Maintenance: assigns anyone, authorizes, closes, audit, FAQ

    public const STAFF = 'staff';                            // Maintenance Staff: takes / transfers OWN tickets, does the work

    public const FINANCE = 'finance';

    public const CUSTODIAN = 'custodian';

    public const PRESIDENT = 'president';

    public const DEPARTMENT_HEAD = 'department_head';   // OIC / Unit Head / Dept. Head

    public const TREASURER = 'treasurer';

    protected $fillable = ['name', 'slug', 'description'];

    /**
     * Everyone on the maintenance side: Staff, Head and Maintenance Admin. Use this wherever the code means
     * "a person who can work on / be assigned a ticket / get the maintenance alerts".
     *
     * @return list<string>
     */
    public static function maintenanceSlugs(): array
    {
        return [self::STAFF, self::HEAD, self::MAINTENANCE_ADMIN];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
