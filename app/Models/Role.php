<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const REQUESTER = 'requester';

    public const MAINTENANCE_ADMIN = 'maintenance_admin';   // Admin: users, settings, categories, e-mail log + everything the Head does

    public const HEAD = 'head';                              // Department Head (scoped to users.department_id): assigns anyone in the department, authorizes, closes, audit, FAQ

    public const STAFF = 'staff';                            // Department Staff (scoped to users.department_id): takes / transfers OWN tickets, does the work

    public const SYSTEM_ADMIN = 'system_admin';              // System Administrator: GLOBAL, not tied to any department (Phase 4A)

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

    /**
     * Everyone who may open the work screens (dashboard + card board): the maintenance team plus the System
     * Administrator. Use this for CheckRole route groups. Do NOT use it for e-mail recipients or the "assign to"
     * list: the System Administrator is not a ticket worker (see maintenanceSlugs()).
     *
     * @return list<string>
     */
    public static function workQueueSlugs(): array
    {
        return array_values(array_unique(array_merge(self::maintenanceSlugs(), self::globalSlugs())));
    }

    /**
     * Roles whose power is limited to ONE department: the one in users.department_id.
     * Configurable in config/school.php (department_scoped_roles). Phase 4B/4C use it to filter and to check.
     *
     * @return list<string>
     */
    public static function departmentScopedSlugs(): array
    {
        return array_values((array) config('school.department_scoped_roles', [self::STAFF, self::HEAD, self::MAINTENANCE_ADMIN]));
    }

    /**
     * Roles that are NOT tied to a department (they act across all departments).
     * Configurable in config/school.php (global_roles).
     *
     * @return list<string>
     */
    public static function globalSlugs(): array
    {
        return array_values((array) config('school.global_roles', [self::SYSTEM_ADMIN]));
    }

    /**
     * Roles that may assign a ticket to staff outside the ticket's department (Phase 4C).
     * Derived from config/permissions.php (cross_department_transfer), never hardcoded here.
     *
     * @return list<string>
     */
    public static function crossDepartmentSlugs(): array
    {
        $slugs = [];

        foreach ((array) config('permissions', []) as $slug => $capabilities) {
            if (in_array('cross_department_transfer', (array) $capabilities, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
