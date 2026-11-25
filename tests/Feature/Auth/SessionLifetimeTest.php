<?php

use App\Enums\Role;
use App\Services\SecurityAnswer;
use Database\Factories\UserFactory;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function recallerName(): string
{
    /** @var SessionGuard $guard */
    $guard = auth()->guard('web');

    return $guard->getRecallerName();
}

/**
 * パスワードと秘密の質問でログインする。保持を選んだかどうかを渡す。
 */
function signIn(string $email, bool $remember): TestResponse
{
    test()->post(route('login.store'), ['email' => $email, 'password' => UserFactory::DEV_PASSWORD] + ($remember ? ['remember' => '1'] : []))
        ->assertRedirect(route('security-question.show'));

    return test()->post(route('security-question.store'), ['answer' => UserFactory::DEV_SECURITY_ANSWER]);
}

/**
 * ブラウザを閉じて開き直した状態（セッションは消え、クッキーだけが残る）。
 */
function closeBrowser(): void
{
    test()->flushSession();
    app('auth')->forgetGuards();
}

test('ログイン画面の「ログイン状態を保持する（30日）」は最初からチェックあり', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('ログイン状態を保持する（30日）')
        ->assertSeeHtml('name="remember"');

    expect((string) $this->get(route('login'))->getContent())->toMatch('/<ui-checkbox[^>]*checked[^>]*name="remember"|<ui-checkbox[^>]*name="remember"[^>]*checked/s');
});

test('T29: 保持を選ぶと、閉じて開き直しても質問なしで入ったまま。保持のクッキーは30日', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $cookie = signIn($user->email, remember: true)->getCookie(recallerName());

    expect($cookie)->not->toBeNull()
        ->and($cookie?->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->and($cookie?->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->getTimestamp() + 5);

    closeBrowser();

    $this->withCookie(recallerName(), (string) $cookie?->getValue())
        ->get(route('company.home', $company))
        ->assertOk();
    $this->assertAuthenticatedAs($user);

    // 24時間を過ぎても切らない
    $this->travel(25)->hours();
    $this->get(route('company.home', $company))->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('T29: 質問に答える前は、保持を選んでいても保持のクッキーを作らない', function (): void {
    $user = createCompanyWithMember(Role::Editor)['user'];

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
        ->assertRedirect(route('security-question.show'))
        ->assertCookieMissing(recallerName());

    $this->post(route('security-question.store'), ['answer' => 'ちがう'])->assertCookieMissing(recallerName());
    $this->assertGuest();
});

test('T29: 保持を選ばなければ保持のクッキーは作らず、24時間で切れる', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    signIn($user->email, remember: false)->assertCookieMissing(recallerName());

    $this->travel(23)->hours();
    $this->get(route('company.home', $company))->assertOk();

    $this->travel(2)->hours();
    $this->get(route('company.home', $company))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('T29: パスワードを変える・秘密の質問の答えを消すと、前の保持のクッキーでは入れない', function (string $case): void {
    $user = createCompanyWithMember(Role::Editor)['user'];
    $cookie = (string) signIn($user->email, remember: true)->getCookie(recallerName())?->getValue();
    closeBrowser();

    $fresh = $user->fresh();
    $case === 'パスワード'
        ? $fresh?->update(['password' => 'another-password-123'])
        : app(SecurityAnswer::class)->reset($fresh);

    $this->withCookie(recallerName(), $cookie)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
})->with(['パスワード', '答えを消す']);

test('T29: でたらめな保持のクッキーでは入れない', function (): void {
    $user = createCompanyWithMember(Role::Editor)['user'];

    $this->withCookie(recallerName(), $user->id.'|'.Str::random(60).'|'.Str::random(60))
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
    $this->assertGuest();
});
