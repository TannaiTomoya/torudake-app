<?php

use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\TrustedDevices;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/**
 * 信頼した端末を DB に作り、クッキーに入れる値（ID|乱数）を返す。
 */
function trustDevice(User $user, ?CarbonInterface $expiresAt = null): string
{
    $token = Str::random(64);

    $device = new TrustedDevice;
    $device->forceFill([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => $expiresAt ?? now()->addDays(TrustedDevices::DAYS),
    ])->save();

    return $device->id.'|'.$token;
}

function currentOtp(): string
{
    return app(Google2FA::class)->getCurrentOtp(UserFactory::DEV_TWO_FACTOR_SECRET);
}

function recallerCookieName(): string
{
    /** @var SessionGuard $guard */
    $guard = auth()->guard('web');

    return $guard->getRecallerName();
}

test('2段階認証の画面に「この端末を覚える」を最初からチェックして出す', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertSee('この端末を覚える（30日はコードを聞きません）')
        ->assertSeeHtml('name="trust_device"');
});

test('チェックありでコードを通すと端末を覚え、次からはパスワードだけで入れる。保持のクッキーは作らない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])
        ->assertRedirect(route('two-factor.login'));

    $response = $this->post(route('two-factor.login.store'), ['code' => currentOtp(), 'trust_device' => '1']);

    $this->assertAuthenticatedAs($user);
    $response->assertCookieMissing(recallerCookieName());
    $cookie = $response->getCookie(TrustedDevices::COOKIE);
    expect($cookie)->not->toBeNull()
        ->and($user->trustedDevices()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'device.trust')->count())->toBe(1);

    $this->post(route('logout'));
    $this->assertGuest();

    $this->withCookie(TrustedDevices::COOKIE, (string) $cookie?->getValue())
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('チェックなしなら端末を覚えない', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $response = $this->post(route('two-factor.login.store'), ['code' => currentOtp()]);

    $this->assertAuthenticatedAs($user);
    $response->assertCookieMissing(TrustedDevices::COOKIE);
    expect($user->trustedDevices()->count())->toBe(0);
});

test('ほかの人の端末・期限切れ・合わない乱数のクッキーでは、コードを聞く。パスワードが違えば入れない', function (string $case): void {
    $user = User::factory()->withTwoFactor()->create();
    $other = User::factory()->withTwoFactor()->create();

    $cookie = match ($case) {
        'ほかの人の端末' => trustDevice($other),
        '期限切れ' => trustDevice($user, now()->subMinute()),
        '合わない乱数' => Str::before(trustDevice($user), '|').'|'.Str::random(64),
        '壊れた値' => 'not-a-device',
    };

    $this->withCookie(TrustedDevices::COOKIE, $cookie)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();

    $this->withCookie(TrustedDevices::COOKIE, trustDevice($user))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
})->with(['ほかの人の端末', '期限切れ', '合わない乱数', '壊れた値']);

test('パスワードを変えたとき・2段階認証を切ったときは、信頼した端末をすべて忘れる', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    trustDevice($user);
    trustDevice($user);

    $user->update(['password' => 'another-password-123']);
    expect($user->trustedDevices()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'device.revoke')->count())->toBe(1);

    $cookie = trustDevice($user);
    app(DisableTwoFactorAuthentication::class)($user);
    expect($user->trustedDevices()->count())->toBe(0);

    $user->forceFill([
        'two_factor_secret' => encrypt(UserFactory::DEV_TWO_FACTOR_SECRET),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->withCookie(TrustedDevices::COOKIE, $cookie)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'another-password-123'])
        ->assertRedirect(route('two-factor.login'));
});

test('設定の画面に信頼している端末の数を出し、「すべて解除」で消せる', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    trustDevice($user);
    trustDevice($user);
    trustDevice($user, now()->subDay());

    Livewire::actingAs($user)->test('pages::settings.security')
        ->assertSeeHtml('data-test="trusted-devices"')
        ->assertSee('2台')
        ->call('revokeTrustedDevices')
        ->assertSee('0台')
        ->assertDontSeeHtml('data-test="trusted-devices-revoke"');

    expect($user->trustedDevices()->count())->toBe(0);
});
