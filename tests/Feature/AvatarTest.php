<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 4: profile photo (private storage, 3 MB, JPG/PNG/WEBP, authorized route, initial fallback). */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_user_can_upload_a_photo_and_it_is_stored_privately(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 400, 300)])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        Storage::disk('local')->assertExists('avatars/'.$user->avatar_path);
        // never the public disk
        Storage::disk('public')->assertMissing('avatars/'.$user->avatar_path);
    }

    public function test_photo_over_3_mb_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('big.jpg')->size(4000)])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_only_jpg_png_webp_are_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('avatar');

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('anim.gif')])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_new_photo_replaces_and_deletes_the_old_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')]);
        $first = $user->fresh()->avatar_path;

        $this->actingAs($user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('b.jpg')]);
        $second = $user->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing('avatars/'.$first);
        Storage::disk('local')->assertExists('avatars/'.$second);
    }

    public function test_photo_can_be_removed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')]);
        $name = $user->fresh()->avatar_path;

        $this->actingAs($user)->delete('/profile/avatar')->assertRedirect('/profile');

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('local')->assertMissing('avatars/'.$name);
    }

    public function test_avatar_route_needs_login_but_any_signed_in_user_can_view(): void
    {
        $owner = User::factory()->create(['avatar_path' => 'sample.jpg']);
        $other = User::factory()->create();
        Storage::disk('local')->put('avatars/sample.jpg', 'fake-image-bytes');

        $this->get(route('avatars.show', $owner))->assertRedirect('/login');

        $this->actingAs($other)->get(route('avatars.show', $owner))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_avatar_route_is_404_without_a_photo(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('avatars.show', $user))->assertNotFound();
    }

    public function test_initial_letter_is_shown_without_a_photo_and_an_image_with_one(): void
    {
        $user = User::factory()->create(['name' => 'maria santos']);

        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('>M</span>', false);

        $this->actingAs($user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')]);

        $this->actingAs($user->fresh())->get('/profile')
            ->assertOk()
            ->assertSee('/avatars/'.$user->id.'?v=', false);
    }
}
