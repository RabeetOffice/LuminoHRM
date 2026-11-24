<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }

    public function test_the_name_can_be_updated_and_is_normalised()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => "  Test \t  User  "])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Test User', $user->refresh()->name);
    }

    public function test_a_posted_email_is_ignored_and_verification_is_unchanged()
    {
        $user = User::factory()->create(['email' => 'keep@example.com']);
        $verifiedAt = $user->email_verified_at;

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Test User', 'email' => 'other@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('keep@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($verifiedAt->equalTo($user->email_verified_at));
    }

    public function test_an_invalid_posted_email_is_not_an_error()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Test User', 'email' => 'not-an-email'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_blank_name_is_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), ['name' => "  \t "])
            ->assertSessionHasErrors('name');
    }

    public function test_the_name_change_is_still_audited()
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'New Name']);

        $log = Activity::where('log_name', 'users')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('New Name', $log->properties['attributes']['name']);
        $this->assertSame('Old Name', $log->properties['old']['name']);
    }
}
