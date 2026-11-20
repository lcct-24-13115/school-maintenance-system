<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A real "Teacher" role (before, teachers were Requester accounts shown as "Student").
 *  - creates the role,
 *  - moves the existing teacher accounts to it: Requester accounts of type FACULTY/STAFF, and Requester accounts with
 *    no type whose email does not start with a digit (a student-number email such as 24-12803@... is a student).
 * Students, parents and alumni are not touched. Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('roles')->updateOrInsert(
            ['slug' => 'teacher'],
            ['name' => 'Teacher', 'description' => 'Files requests; advises students and approves their complaints first', 'updated_at' => $now, 'created_at' => $now],
        );

        $teacher = DB::table('roles')->where('slug', 'teacher')->value('id');
        $requester = DB::table('roles')->where('slug', 'requester')->value('id');

        if ($teacher === null || $requester === null) {
            return;
        }

        DB::table('users')
            ->where('role_id', $requester)
            ->where(function ($q) {
                $q->where('requester_type', 'FACULTY/STAFF')
                    ->orWhere(function ($q) {
                        $q->where(fn ($t) => $t->whereNull('requester_type')->orWhere('requester_type', ''));
                        foreach (range(0, 9) as $digit) {
                            $q->where('email', 'not like', $digit.'%');
                        }
                    });
            })
            ->update(['role_id' => $teacher]);
    }

    public function down(): void
    {
        $teacher = DB::table('roles')->where('slug', 'teacher')->value('id');
        $requester = DB::table('roles')->where('slug', 'requester')->value('id');

        if ($teacher !== null && $requester !== null) {
            DB::table('users')->where('role_id', $teacher)->update(['role_id' => $requester]);
        }

        DB::table('roles')->where('slug', 'teacher')->delete();
    }
};
