<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\SecurityAnswer;
use App\Services\TrustedDevices;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

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

function loginWithPassword(User $user, string $password = UserFactory::DEV_PASSWORD)
{
    return test()->post(route('login.store'), ['email' => $user->email, 'password' => $password]);
}

function answerQuestion(string $answer = UserFactory::DEV_SECURITY_ANSWER, bool $trust = false)
{
    return test()->post(route('security-question.store'), ['answer' => $answer] + ($trust ? ['trust_device' => '1'] : []));
}

test('T02: 初めてのログイン：パスワード → 答えを決める画面 → ホーム。ホームに初期設定のカードは出ない', function (Role $role, bool $submitOnly, string $landing): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, submitOnly: $submitOnly, withSecurityAnswer: false);

    loginWithPassword($user)->assertRedirect(route('dashboard', absolute: false));
    $this->get(route('dashboard'))->assertRedirect(route('security-answer.setup'));
    $this->get(route('security-answer.setup'))
        ->assertOk()
        ->assertSee(SecurityAnswer::QUESTION)
        ->assertSee(SecurityAnswer::HINT)
        ->assertDontSee(route($landing, $company));

    Livewire::actingAs($user)->test('pages::setup.security-answer')
        ->set('answer', 'かくう町')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route($landing, $company));

    $user->refresh();
    expect($user->hasSecurityAnswer())->toBeTrue()
        ->and($user->security_answer_hash)->not->toContain('かくう')
        ->and($user->trustedDevices()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'security_answer.set')->count())->toBe(1)
        ->and(AuditLog::query()->get()->toJson(JSON_UNESCAPED_UNICODE))->not->toContain('かくう');

    $this->actingAs($user)->get(route($landing, $company))
        ->assertOk()
        ->assertDontSee('最初にやること')
        ->assertDontSee('2段階認証');
})->with([
    '社長' => [Role::Owner, false, 'company.home'],
    '経理' => [Role::Editor, false, 'company.home'],
    '送るだけ' => [Role::Editor, true, 'company.submit'],
]);

test('答えを決めていない人は、どの画面を開いても答えを決める画面へ回る', function (bool $submitOnly): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($submitOnly ? Role::Editor : Role::Owner, submitOnly: $submitOnly, withSecurityAnswer: false);
    $this->actingAs($user);

    $names = $submitOnly ? ['company.submit'] : ['company.home', 'company.submit', 'company.owner', 'company.entries.index', 'company.live', 'company.ai', 'company.settings', 'company.workbook'];

    foreach ($names as $name) {
        $this->get(route($name, $company))->assertRedirect(route('security-answer.setup'));
    }

    $this->get(route('home'))->assertRedirect(route('security-answer.setup'));
    $this->get(route('dashboard'))->assertRedirect(route('security-answer.setup'));
})->with([false, true]);

test('会社の画面のルートには、すべて秘密の質問のミドルウェアがかかっている', function (): void {
    $companyRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'company.'));

    expect($companyRoutes)->not->toBeEmpty();
    $companyRoutes->each(fn ($route) => expect($route->gatherMiddleware())->toContain('security-answer'));
});

test('もう決めてある人は、答えを決める画面で書き換えられない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    Livewire::actingAs($user)->test('pages::setup.security-answer')
        ->assertRedirect(route('company.home', $company));

    // 画面を開いたあとで、別の端末が先に決めた
    $late = User::factory()->create();
    $component = Livewire::actingAs($late)->test('pages::setup.security-answer');
    app(SecurityAnswer::class)->set($late, 'さきの答え');
    $before = $late->security_answer_hash;

    $component->set('answer', 'べつの答え')->call('save');

    expect($late->fresh()?->security_answer_hash)->toBe($before)
        ->and($late->trustedDevices()->count())->toBe(0);
});

test('空の答え・空白だけ・長すぎる答えは決められない', function (string $answer): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::setup.security-answer')
        ->set('answer', $answer)
        ->call('save')
        ->assertHasErrors('answer');

    expect($user->fresh()?->hasSecurityAnswer())->toBeFalse();
})->with(['', '　 　', str_repeat('あ', 25)]);

test('決めてある人は、新しい端末ではパスワードのあとに質問を聞かれ、答えるまでログインしていない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $this->get(route('company.owner', $company))->assertRedirect(route('login'));
    loginWithPassword($user)->assertRedirect(route('security-question.show'));
    $this->assertGuest();
    $this->get(route('company.home', $company))->assertRedirect(route('login'));

    $this->get(route('security-question.show'))
        ->assertOk()
        ->assertSee(SecurityAnswer::QUESTION)
        ->assertSee('この端末を覚える（30日は質問しません）')
        ->assertSeeHtml('name="trust_device"');

    answerQuestion()->assertRedirect(route('dashboard'))->assertSessionMissing('url.intended');
    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertRedirect(route('company.home', $company));
});

test('書き方が違っても同じ答えとして通る', function (string $set, string $typed): void {
    $user = User::factory()->create();
    app(SecurityAnswer::class)->set($user, $set);

    loginWithPassword($user);
    answerQuestion($typed)->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
})->with([
    '町の有無' => ['かくう町', 'かくう'],
    '市の有無' => ['かくう', 'かくう市'],
    'カタカナ' => ['かくう', 'カクウ'],
    '半角カタカナ' => ['がくう', 'ｶﾞｸｳ'],
    '空白' => ['かくう町', '　か くう 町 '],
    '全角と半角・大文字と小文字' => ['Abc1', 'ａｂｃ１'],
]);

test('違う答えでは入れない。5回まちがえると15分止まり、そのあいだは合っていても通さない', function (): void {
    $user = User::factory()->withSecurityAnswer()->create();
    loginWithPassword($user);

    foreach (range(1, 4) as $_) {
        answerQuestion('ちがう')->assertSessionHasErrors(['answer' => '答えが違います。']);
    }
    answerQuestion('ちがう')->assertSessionHasErrors('answer');
    expect($user->fresh()?->security_answer_locked_until)->not->toBeNull();

    answerQuestion()->assertSessionHasErrors('answer');
    $this->assertGuest();

    // ログインし直しても、止まったまま
    loginWithPassword($user);
    answerQuestion()->assertSessionHasErrors('answer');
    $this->assertGuest();

    $this->travel(16)->minutes();
    loginWithPassword($user);
    answerQuestion()->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    expect(AuditLog::query()->where('action', 'security_answer.fail')->count())->toBe(4)
        ->and(AuditLog::query()->where('action', 'security_answer.lock')->count())->toBe(1)
        ->and(AuditLog::query()->get()->toJson(JSON_UNESCAPED_UNICODE))->not->toContain('ちがう');
});

test('パスワードを通していない人・10分を過ぎた人・そのあとパスワードが変わった人は、質問の画面を使えない', function (string $case): void {
    $user = User::factory()->withSecurityAnswer()->create();

    if ($case !== '通していない') {
        loginWithPassword($user);
    }
    if ($case === '10分を過ぎた') {
        $this->travel(11)->minutes();
    }
    if ($case === 'パスワードが変わった') {
        $user->update(['password' => 'another-password-123']);
    }

    $this->get(route('security-question.show'))->assertRedirect(route('login'));
    answerQuestion()->assertRedirect(route('login'));
    $this->assertGuest();
})->with(['通していない', '10分を過ぎた', 'パスワードが変わった']);

test('チェックありで答えると端末を覚え、次からはパスワードだけで入れる。チェックなしなら覚えない', function (bool $trust): void {
    $user = User::factory()->withSecurityAnswer()->create();
    loginWithPassword($user);
    $response = answerQuestion(trust: $trust);

    if (! $trust) {
        $response->assertCookieMissing(TrustedDevices::COOKIE);
        expect($user->trustedDevices()->count())->toBe(0);

        return;
    }

    $cookie = $response->getCookie(TrustedDevices::COOKIE);
    expect($cookie)->not->toBeNull()->and($user->trustedDevices()->count())->toBe(1);

    $this->post(route('logout'));
    $this->withCookie(TrustedDevices::COOKIE, (string) $cookie?->getValue())
        ->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::DEV_PASSWORD])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
})->with([true, false]);

test('ほかの人の端末・期限切れ・合わない乱数のクッキーでは質問を聞く。パスワードが違えば入れない', function (string $case): void {
    $user = User::factory()->withSecurityAnswer()->create();
    $other = User::factory()->withSecurityAnswer()->create();

    $cookie = match ($case) {
        'ほかの人の端末' => trustDevice($other),
        '期限切れ' => trustDevice($user, now()->subMinute()),
        '合わない乱数' => Str::before(trustDevice($user), '|').'|'.Str::random(64),
        '壊れた値' => 'not-a-device',
    };

    $this->withCookie(TrustedDevices::COOKIE, $cookie)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('security-question.show'));
    $this->assertGuest();

    $this->withCookie(TrustedDevices::COOKIE, trustDevice($user))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
})->with(['ほかの人の端末', '期限切れ', '合わない乱数', '壊れた値']);

test('TOTP を設定済みだった人は、次のログインで秘密の質問に切り替わる（6桁は聞かない）', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner, withSecurityAnswer: false);
    $user->forceFill([
        'two_factor_secret' => encrypt('TORUDAKEDEVLOCAL'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    loginWithPassword($user)->assertRedirect(route('dashboard', absolute: false));
    $this->get(route('dashboard'))->assertRedirect(route('security-answer.setup'));
    $this->get(route('company.home', $company))->assertRedirect(route('security-answer.setup'));

    Livewire::actingAs($user)->test('pages::setup.security-answer')
        ->set('answer', 'かくう')->call('save')->assertRedirect(route('company.home', $company));

    expect($user->fresh()?->two_factor_secret)->not->toBeNull();
});

test('忘れたときのコマンドで消すと、端末も忘れ、次のログインで決め直しになる', function (): void {
    $user = User::factory()->withSecurityAnswer()->create();
    $cookie = trustDevice($user);

    $this->artisan('torudake:reset-security-answer', ['email' => $user->email])->assertSuccessful();

    expect($user->fresh()?->hasSecurityAnswer())->toBeFalse()
        ->and($user->trustedDevices()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'security_answer.reset')->count())->toBe(1);

    $this->withCookie(TrustedDevices::COOKIE, $cookie)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->get(route('dashboard'))->assertRedirect(route('security-answer.setup'));

    $this->artisan('torudake:reset-security-answer', ['email' => 'nobody@example.com'])->assertFailed();
});

test('パスワードか秘密の質問を変えると、信頼した端末をすべて忘れる', function (): void {
    $user = User::factory()->withSecurityAnswer()->create();
    trustDevice($user);
    trustDevice($user);

    $user->update(['password' => 'another-password-123']);
    expect($user->trustedDevices()->count())->toBe(0);

    trustDevice($user);
    Livewire::actingAs($user)->test('pages::settings.security')
        ->set('security_answer', 'あたらしい')
        ->call('changeSecurityAnswer')
        ->assertHasNoErrors();

    expect($user->trustedDevices()->count())->toBe(0);
    $this->post(route('logout'));
    loginWithPassword($user, 'another-password-123');
    answerQuestion('あたらしい')->assertRedirect(route('dashboard'));
});

test('設定の画面に信頼している端末の数を出し、「すべて解除」で消せる', function (): void {
    $user = User::factory()->withSecurityAnswer()->create();
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

test('TOTP と顔でログインは使えない（経路がなく、ログインと設定の画面にも出ない）', function (): void {
    foreach (['two-factor.login', 'two-factor.enable', 'passkey.login', 'passkey.login-options', 'passkey.store'] as $name) {
        expect(Route::has($name))->toBeFalse($name);
    }

    $this->get(route('login'))->assertOk()->assertDontSee('顔でログイン');

    $this->actingAs(User::factory()->withSecurityAnswer()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('秘密の質問を変える')
        ->assertDontSee('顔でログイン')
        ->assertDontSee(__('Two-factor authentication'));
});
