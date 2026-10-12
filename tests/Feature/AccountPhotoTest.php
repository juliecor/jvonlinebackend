<?php

namespace Tests\Feature;

use App\Models\Realty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Everyone can give their account a profile photo (shown at the top of their sidebar), change it or
 * remove it; the old file goes with it.
 */
class AccountPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_profile_photo_can_be_added_changed_and_removed(): void
    {
        $disk = Storage::fake(config('filesystems.uploads'));
        $realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $realty->id]);
        Sanctum::actingAs($agent);

        $this->getJson('/api/auth/me')->assertJsonPath('photo_url', null);

        $first = $this->postJson('/api/account/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 400, 400)])->assertOk()->json('photo_url');
        $this->assertNotNull($first);
        $path = $agent->fresh()->photo_path;
        $disk->assertExists($path);
        $this->assertStringStartsWith("users/{$agent->id}/", $path);
        $this->getJson('/api/auth/me')->assertJsonPath('photo_url', $first);

        $this->postJson('/api/account/photo', ['photo' => UploadedFile::fake()->image('new.png', 300, 300)])->assertOk();
        $disk->assertMissing($path);
        $newPath = $agent->fresh()->photo_path;
        $disk->assertExists($newPath);

        $this->postJson('/api/account/photo', ['photo' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf')])->assertJsonValidationErrors(['photo' => 'Choose a photo']);
        $this->postJson('/api/account/photo', ['photo' => UploadedFile::fake()->image('huge.jpg')->size(6000)])->assertJsonValidationErrors(['photo' => '5 MB']);

        $this->deleteJson('/api/account/photo')->assertOk()->assertJsonPath('photo_url', null);
        $disk->assertMissing($newPath);
        $this->assertNull($agent->fresh()->photo_path);
    }

    public function test_the_photo_path_cannot_be_set_through_the_details_form(): void
    {
        $realty = Realty::create(['name' => 'Johndorf Ventures Corporation', 'slug' => 'johndorf', 'status' => Realty::STATUS_ACTIVE, 'registered_at' => now()]);
        $agent = User::factory()->create(['role' => User::ROLE_AGENT, 'realty_id' => $realty->id]);
        Sanctum::actingAs($agent);

        $this->patchJson('/api/account', ['name' => 'Rosa', 'email' => $agent->email, 'photo_path' => 'https://example.com/x.jpg'])->assertOk();
        $this->assertNull($agent->fresh()->photo_path);
    }
}
