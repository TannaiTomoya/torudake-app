<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_the_app_has_no_way_to_delete_an_account(): void
    {
        $user = User::factory()->withSecurityAnswer()->create();

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSeeInOrder(['<title>', 'プロフィール設定 - ', '</title>'], false)
            ->assertDontSee('アカウントを削除')
            ->assertDontSee('delete-user', false);

        foreach (['pages::settings.delete-user-form', 'pages::settings.delete-user-modal'] as $name) {
            $this->assertFalse(app('livewire')->exists($name), $name);
        }
    }

    public function test_settings_pages_have_japanese_titles(): void
    {
        $user = User::factory()->withSecurityAnswer()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get(route('security.edit'))->assertSeeInOrder(['<title>', 'セキュリティ設定 - ', '</title>'], false);
        $this->get(route('appearance.edit'))->assertSeeInOrder(['<title>', 'テーマ設定 - ', '</title>'], false);
    }
}
