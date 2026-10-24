<?php

use App\Models\User;
use Database\Factories\UserFactory;
use PragmaRX\Google2FA\Google2FA;

test('初期データの人は、README の鍵で作った6桁のコードでログインできる', function () {
    $this->seed();

    $this->post(route('login.store'), [
        'email' => 'owner@example.com',
        'password' => UserFactory::DEV_PASSWORD,
    ])->assertRedirect(route('two-factor.login'));

    $code = app(Google2FA::class)->getCurrentOtp(UserFactory::DEV_TWO_FACTOR_SECRET);

    $this->post(route('two-factor.login.store'), ['code' => $code]);

    $this->assertAuthenticatedAs(User::where('email', 'owner@example.com')->firstOrFail());
});

test('初期データの人は、README の復旧コードでもログインできる', function () {
    $this->seed();

    $this->post(route('login.store'), [
        'email' => 'editor@example.com',
        'password' => UserFactory::DEV_PASSWORD,
    ])->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), ['recovery_code' => UserFactory::DEV_RECOVERY_CODE]);

    $this->assertAuthenticatedAs(User::where('email', 'editor@example.com')->firstOrFail());
});

test('本番では、開発用のパスワードで人を作れない', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => User::factory()->make())->toThrow(LogicException::class);
});

test('本番では、開発用の2段階認証の鍵を使えない', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => User::factory()->withTwoFactor())->toThrow(LogicException::class);
});

test('本番では、初期データを流しても人は作られない', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->seed();

    expect(User::count())->toBe(0);
});

test('画面にスターターキットのリンクが出ない', function () {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();

    $this->actingAs($user)
        ->get(route('company.home', $company))
        ->assertOk()
        ->assertDontSee('livewire-starter-kit')
        ->assertDontSee('laravel.com/docs')
        ->assertSee('ホーム');
});
