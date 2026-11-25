<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

const NEW_PASSWORD = 'Atarashii-Pass-2026';

function resetPassword(string $token, string $email, bool $remember = false): TestResponse
{
    return test()->post(route('password.update'), [
        'token' => $token,
        'email' => $email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ] + ($remember ? ['remember' => '1'] : []));
}

test('はじめての人は、パスワードを決めたらそのまま答えを決める画面に着く（2回目のログインはない）', function (): void {
    $user = createCompanyWithMember(Role::Editor, withSecurityAnswer: false)['user'];

    resetPassword(Password::broker()->createToken($user), $user->email, remember: true)
        ->assertRedirect(route('dashboard'))
        ->assertCookie(auth()->guard('web')->getRecallerName());

    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertRedirect(route('security-answer.setup'));
});

test('答えを決めてある人は、パスワードを決め直しても秘密の質問に答えるまで入れない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    resetPassword(Password::broker()->createToken($user), $user->email)
        ->assertRedirect(route('security-question.show'));

    $this->assertGuest();
    $this->get(route('company.home', $company))->assertRedirect(route('login'));

    $this->post(route('security-question.store'), ['answer' => 'ちがう']);
    $this->assertGuest();

    $this->post(route('security-question.store'), ['answer' => 'テスト町'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('ログインしたままリンクを開くと、ホームへ飛ばさず「ログアウトして続ける」を出す', function (): void {
    $current = createCompanyWithMember(Role::Owner)['user'];
    $other = createCompanyWithMember(Role::Editor)['user'];
    $token = Password::broker()->createToken($other);
    $url = route('password.reset', ['token' => $token, 'email' => $other->email]);

    $this->actingAs($current)->get($url)
        ->assertOk()
        ->assertSee('ログインしたままです')
        ->assertSee($current->name)
        ->assertSee('ログアウトして続ける');
    $this->assertAuthenticatedAs($current);

    $this->post(route('password.reset.switch'), ['token' => $token, 'email' => $other->email])
        ->assertRedirect($url);
    $this->assertGuest();
    $this->get($url)->assertOk()->assertSee('パスワードを再設定')->assertSee('ログイン状態を保持する（30日）');
});

test('他人のメールアドレスを書き換えても、他人のパスワードは変わらない', function (): void {
    $mine = User::factory()->create();
    $victim = User::factory()->create();

    resetPassword(Password::broker()->createToken($mine), $victim->email)->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(Hash::check('password', (string) $victim->fresh()?->password))->toBeTrue();
});

test('期限切れ・使用済みのリンクと、登録のない宛先は、同じ日本語の案内になる', function (): void {
    $user = User::factory()->create();

    $used = Password::broker()->createToken($user);
    resetPassword($used, $user->email);
    $this->post(route('logout'));

    $expired = Password::broker()->createToken($user);
    $this->travel(61)->minutes();

    foreach ([[$used, $user->email], [$expired, $user->email], ['でたらめ', 'nobody@example.com']] as [$token, $email]) {
        resetPassword($token, $email)->assertSessionHasErrors(['email' => __('passwords.token')]);
        $this->assertGuest();
    }

    expect(__('passwords.user'))->toBe(__('passwords.token'))->toContain('このリンクは使えません');
});
