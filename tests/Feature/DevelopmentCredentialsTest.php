<?php

use App\Models\User;
use Database\Factories\UserFactory;

test('初期データの人は、README の答えでログインできる', function () {
    $this->seed();

    $this->post(route('login.store'), [
        'email' => 'owner@example.com',
        'password' => UserFactory::DEV_PASSWORD,
    ])->assertRedirect(route('security-question.show'));

    $this->post(route('security-question.store'), ['answer' => UserFactory::DEV_SECURITY_ANSWER]);

    $this->assertAuthenticatedAs(User::where('email', 'owner@example.com')->firstOrFail());
});

test('本番では、開発用のパスワードで人を作れない', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => User::factory()->make())->toThrow(LogicException::class);
});

test('本番では、開発用の秘密の質問の答えを使えない', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => User::factory()->withSecurityAnswer())->toThrow(LogicException::class);
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
