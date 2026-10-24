<?php

use App\Enums\Role;
use App\Models\Company;
use App\Models\SourceRecord;
use App\Models\User;
use Database\Factories\UserFactory;
use PragmaRX\Google2FA\Google2FA;

/** @return array<string, string> */
function companyRoutingTargets(Company $company, SourceRecord $source): array
{
    return [
        'home' => route('company.home', $company),
        'submit' => route('company.submit', $company),
        'sources.index' => route('company.sources.index', $company),
        'sources.show' => route('company.sources.show', [$company, $source]),
        'entries.index' => route('company.entries.index', $company),
        'entries.show' => route('company.entries.show', [$company, $source->journal_entry_id]),
        'owner' => route('company.owner', $company),
        'reports' => route('company.reports', $company),
        'live' => route('company.live', $company),
        'ai' => route('company.ai', $company),
        'settings' => route('company.settings', $company),
        'evidences.image' => route('company.evidences.image', [$company, $source->evidence_id]),
        'workbook' => route('company.workbook', $company),
        'exports.training' => route('company.exports.training', $company),
    ];
}

dataset('landing roles', [
    '経理' => [Role::Editor, false, 'company.home'],
    '従業員' => [Role::Editor, true, 'company.submit'],
    '社長' => [Role::Owner, false, 'company.home'],
    '管理者' => [Role::Admin, false, 'company.home'],
    '承認者' => [Role::Approver, false, 'company.home'],
    '閲覧のみ' => [Role::Viewer, false, 'company.home'],
]);

test('ログイン前の古い遷移先より役割別のホームを優先する', function (Role $role, bool $submitOnly, string $landing): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, submitOnly: $submitOnly, withTwoFactor: false);

    $this->get(route('company.owner', $company))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::DEV_PASSWORD])
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionMissing('url.intended');
    $this->get(route('dashboard'))->assertRedirect(route($landing, $company));
    $this->get(route($landing, $company))->assertOk()->assertSee('data-test="two-factor-first"', false);
})->with('landing roles');

test('2段階認証ログイン後も古い遷移先を消し役割別のホームを開く', function (Role $role, bool $submitOnly, string $landing): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, submitOnly: $submitOnly);

    $this->get(route('company.owner', $company))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => UserFactory::DEV_PASSWORD])
        ->assertRedirect(route('two-factor.login'));
    $code = app(Google2FA::class)->getCurrentOtp(UserFactory::DEV_TWO_FACTOR_SECRET);
    $this->post(route('two-factor.login.store'), ['code' => $code])
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionMissing('url.intended');
    $this->get(route('dashboard'))->assertRedirect(route($landing, $company));
    $this->get(route($landing, $company))->assertOk()->assertDontSee('data-test="two-factor-first"', false);
})->with('landing roles');

test('認証前の他社会社URLと無効な所属はホームへ転送せず404にする', function (bool $disabled): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Owner, withTwoFactor: false);
    if ($disabled) {
        $membership->forceFill(['disabled_at' => now()])->save();
    } else {
        $company = createCompanyWithMember(Role::Owner)['company'];
    }

    foreach (['company.home', 'company.owner', 'company.sources.index', 'company.reports', 'company.workbook'] as $route) {
        $this->actingAs($user)->get(route($route, $company))->assertNotFound();
    }
})->with([false, true]);

test('2段階認証前に別の所属会社を開いたらその会社の入口へ戻る', function (bool $submitOnly, string $landing): void {
    ['company' => $first, 'user' => $user] = createCompanyWithMember(Role::Editor, withTwoFactor: false);
    ['company' => $second, 'membership' => $membership] = createCompanyWithMember(Role::Editor, submitOnly: $submitOnly);
    $membership->forceFill(['user_id' => $user->id])->save();

    $this->actingAs($user)->get(route('company.home', $first))->assertOk();
    $this->get(route('company.evidences.image', [$second, '01900000-0000-7000-8000-000000000001']))
        ->assertRedirect(route($landing, $second))
        ->assertSessionHas(User::CURRENT_COMPANY_SESSION_KEY, $second->id);
    $this->get(route($landing, $second))->assertOk()->assertSee('data-test="two-factor-first"', false);
})->with([
    '経理' => [false, 'company.home'],
    '送るだけ' => [true, 'company.submit'],
]);

test('2段階認証前のホームには会社の業務リンクや件数を出さない', function (Role $role): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, withTwoFactor: false);

    $this->actingAs($user)->get(route('company.home', $company))->assertOk()
        ->assertSee('data-test="sidebar-home"', false)
        ->assertSee('data-test="two-factor-first"', false)
        ->assertDontSee('data-test="sidebar-owner"', false)
        ->assertDontSee('data-test="sidebar-sources"', false)
        ->assertDontSee('data-test="sidebar-entries"', false)
        ->assertDontSee('data-test="sidebar-workbook"', false)
        ->assertDontSee('data-test="sidebar-live"', false)
        ->assertDontSee(route('company.settings', $company));
})->with([Role::Owner, Role::Editor]);

test('社長のホームから送る・直す・AIの記録にも進める', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);

    $this->actingAs($user)->get(route('company.home', $company))->assertOk()
        ->assertSee(route('company.submit', $company))
        ->assertSee(route('company.sources.index', $company))
        ->assertSee(route('company.ai', $company));
});

test('全会社ルートで役割に応じた画面とダウンロードを開ける', function (Role $role, bool $submitOnly): void {
    ['company' => $company, 'user' => $sender] = createCompanyWithMember(Role::Editor, submitOnly: true);
    $source = sendReceipt($company, $sender);
    $user = $submitOnly ? $sender : createCompanyWithMember($role, company: $company)['user'];

    $allowed = $submitOnly ? ['submit', 'evidences.image'] : match ($role) {
        Role::Owner, Role::Admin => array_keys(companyRoutingTargets($company, $source)),
        Role::Editor, Role::Approver => ['home', 'submit', 'sources.index', 'sources.show', 'entries.index', 'entries.show', 'evidences.image', 'workbook'],
        Role::Viewer => ['home', 'sources.index', 'entries.index', 'entries.show', 'evidences.image', 'workbook'],
    };

    foreach (companyRoutingTargets($company, $source) as $name => $url) {
        $response = $this->actingAs($user)->get($url)->assertStatus(in_array($name, $allowed, true) ? 200 : 403);

        if (in_array($name, $allowed, true) && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            // 表示した社内リンクも実際に開く。権限のない画面へのリンク・未定義ルートを検出する。
            preg_match_all('/href="([^"]+)"/', $response->getContent(), $matches);
            foreach (array_unique($matches[1]) as $href) {
                $href = html_entity_decode($href);
                if (str_starts_with($href, route('company.home', $company))) {
                    $this->get($href)->assertOk();
                }
            }
        }
    }
})->with('landing roles');

test('全会社ルートで未ログインはログインへ、他社と無効な所属は404', function (): void {
    ['company' => $company, 'user' => $owner, 'membership' => $membership] = createCompanyWithMember(Role::Owner);
    ['user' => $other] = createCompanyWithMember(Role::Owner);
    $source = sendReceipt($company, $owner);

    foreach (companyRoutingTargets($company, $source) as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
    foreach (companyRoutingTargets($company, $source) as $url) {
        $this->actingAs($other)->get($url)->assertNotFound();
    }
    $membership->forceFill(['disabled_at' => now()])->save();
    foreach (companyRoutingTargets($company, $source) as $url) {
        $this->actingAs($owner)->get($url)->assertNotFound();
    }
});

test('認証APIのJSON応答はFortifyの形式を保つ', function (): void {
    $user = User::factory()->create();
    $this->postJson(route('login.store'), ['email' => $user->email, 'password' => UserFactory::DEV_PASSWORD])
        ->assertOk()->assertExactJson(['two_factor' => false]);

    $this->post(route('logout'));
    $user = User::factory()->withTwoFactor()->create();
    $this->postJson(route('login.store'), ['email' => $user->email, 'password' => UserFactory::DEV_PASSWORD])
        ->assertOk()->assertExactJson(['two_factor' => true]);
    $code = app(Google2FA::class)->getCurrentOtp(UserFactory::DEV_TWO_FACTOR_SECRET);
    $this->postJson(route('two-factor.login.store'), ['code' => $code])->assertNoContent();
});
