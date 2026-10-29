<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TmpSeedDebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug(): void
    {
        fwrite(STDERR, "ENV: ".app()->environment()." | DB: ".config('database.default')."\n");
        fwrite(STDERR, "Users before: ".\App\Models\User::count()."\n");
        $this->seed();
        fwrite(STDERR, "Users after: ".\App\Models\User::count()."\n");
        fwrite(STDERR, "Tickets after: ".\App\Models\MaintenanceRequest::count()."\n");
        $this->assertTrue(true);
    }
}
