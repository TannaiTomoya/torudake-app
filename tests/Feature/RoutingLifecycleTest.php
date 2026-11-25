<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Drawer\Utils;
use Livewire\Livewire;

/** @return array<string, mixed> */
function pageUpdatePayload(TestResponse $response, string $method = '$refresh', array $params = [], string $component = ''): array
{
    $snapshot = Utils::extractAttributeDataFromHtml($response->getContent(), 'wire:snapshot');
    if ($component !== '') {
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        foreach ($matches[1] as $encoded) {
            $candidate = json_decode(html_entity_decode($encoded, ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
            if ($candidate['memo']['name'] === $component) {
                $snapshot = $candidate;
                break;
            }
        }
        expect($snapshot['memo']['name'])->toBe($component);
    }
    expect($snapshot)->toBeArray()->not->toBeEmpty();

    return ['components' => [[
        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        'updates' => [],
        'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
    ]]];
}

function postPageUpdate(array $payload): TestResponse
{
    // 別のHTTPリクエストと同様に、前回のLivewireの処理状態を引き継がない。
    app('livewire')->flushState();

    return test()->postJson(app('livewire')->getUpdateUri(), $payload, ['X-Livewire' => 'true', 'Accept' => 'text/html']);
}

test('会社画面の操作通信でもメール確認と秘密の質問を再確認する', function (string $field, string $destination): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $page = $this->actingAs($user)->get(route('company.entries.index', $company))->assertOk();
    $payload = pageUpdatePayload($page);

    $user->forceFill([$field => null])->save();
    $this->actingAs($user->fresh());

    postPageUpdate($payload)->assertRedirect($destination === 'company.home' ? route($destination, $company) : route($destination));
})->with([
    'メールを変更した後' => ['email_verified_at', 'verification.notice'],
    '秘密の質問の答えを消された後' => ['security_answer_hash', 'security-answer.setup'],
]);

test('会社画面の操作通信でも所属の失効を404で拒否する', function (): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Owner);
    $payload = pageUpdatePayload($this->actingAs($user)->get(route('company.home', $company))->assertOk());

    $membership->forceFill(['disabled_at' => now()])->save();
    postPageUpdate($payload)->assertNotFound();
});

test('社長専用画面の操作通信でもロール変更を403で拒否する', function (string $route): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Owner);
    $payload = pageUpdatePayload($this->actingAs($user)->get(route($route, $company))->assertOk());

    $membership->forceFill(['role' => Role::Editor])->save();
    postPageUpdate($payload)->assertForbidden();
})->with(['company.owner', 'company.reports', 'company.ai', 'company.live', 'company.settings']);

test('別タブで送るだけに変わった人の原資料更新通信を403で拒否する', function (): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Editor);
    $payload = pageUpdatePayload($this->actingAs($user)->get(route('company.sources.index', $company))->assertOk());
    $membership->forceFill(['submit_only' => true])->save();

    postPageUpdate($payload)->assertForbidden();
});

test('セキュリティ設定の操作通信でもパスワード再確認の期限を守る', function (): void {
    $user = User::factory()->withSecurityAnswer()->create();
    $payload = pageUpdatePayload($this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))->assertOk(), 'revokeTrustedDevices');

    session(['auth.password_confirmed_at' => 0]);
    postPageUpdate($payload)->assertRedirect(route('password.confirm'))
        ->assertSessionHas('url.intended', route('security.edit'));

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect(route('security.edit'));
});

test('答えを消されたあとに送信画面の通信を直接送っても写真は増えない', function (): void {
    ['company' => $company, 'user' => $worker] = createCompanyWithMember(Role::Editor, submitOnly: true);
    $payload = pageUpdatePayload($this->actingAs($worker)->get(route('company.submit', $company))->assertOk(), 'send');

    $worker->forceFill(['security_answer_hash' => null, 'security_answer_set_at' => null])->save();
    $this->actingAs($worker->fresh());

    postPageUpdate($payload)->assertRedirect(route('security-answer.setup'));

    Livewire::actingAs($worker->fresh())->test('pages::company.submit', ['company' => $company])->assertForbidden();
    expect($company->sourceRecords()->count())->toBe(0);
});

test('ホームの件数取得メソッドを直接呼んでも件数は返らない（computed は画面の外から呼べない）', function (string $method): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $payload = pageUpdatePayload($this->actingAs($owner)->get(route('company.home', $company))->assertOk(), $method);

    expect(postPageUpdate($payload)->isSuccessful())->toBeFalse();
})->with(['remaining', 'concerns']);

test('メール確認後も古い社長URLに戻らず役割別の入口へ進む', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $editor->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($editor)->get(route('company.owner', $company))->assertRedirect(route('verification.notice'));

    $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $editor->id,
        'hash' => sha1($editor->email),
    ]);
    $this->get($signed)->assertRedirect(route('dashboard', absolute: false).'?verified=1')->assertSessionMissing('url.intended');
    $this->get(route('dashboard'))->assertRedirect(route('company.home', $company));
});

test('確認済みのプロフィールからも古いURLに戻さない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    session(['url.intended' => route('company.owner', $company)]);

    Livewire::actingAs($editor)->test('pages::settings.profile')
        ->call('resendVerificationNotification')->assertRedirect(route('company.home', $company));
});

test('メール未確認の個人設定に会社の業務リンクを出さない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $owner->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($owner)->get(route('profile.edit'))->assertOk()
        ->assertDontSee('data-test="sidebar-owner"', false)
        ->assertDontSee('data-test="sidebar-workbook"', false)
        ->assertDontSee(route('company.settings', $company));
    $this->get(route('home'))->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))->assertSee(route('profile.edit'));
});

test('所属なしのホームは案内を出し個人設定からも戻れる', function (): void {
    $user = User::factory()->withSecurityAnswer()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('会社への所属がありません');
    $this->get(route('profile.edit'))->assertOk()->assertSee('data-test="back-to-home"', false)->assertSee(route('dashboard'));
});

test('権限や認証が失効した操作通信では会社の設定を書き換えない', function (string $change, int $status): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Owner);
    $payload = pageUpdatePayload($this->actingAs($user)->get(route('company.settings', $company))->assertOk(), 'saveCompany');
    $payload['components'][0]['updates']['name'] = '変更されてはいけない会社名';
    $before = $company->name;

    if (in_array($change, ['email_verified_at', 'security_answer_hash'], true)) {
        $user->forceFill([$change => null])->save();
        $this->actingAs($user->fresh());
    } else {
        $membership->forceFill($change === 'role' ? ['role' => Role::Editor] : ['disabled_at' => now()])->save();
    }

    postPageUpdate($payload)->assertStatus($status);
    expect($company->fresh()->name)->toBe($before);
})->with([
    'メール未確認' => ['email_verified_at', 302],
    '秘密の質問の答えなし' => ['security_answer_hash', 302],
    '所属失効' => ['disabled_at', 404],
    '役割変更' => ['role', 403],
]);

test('認証済みの操作通信は正常に画面を更新できる', function (Role $role, bool $submitOnly, string $route): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, submitOnly: $submitOnly);
    $payload = pageUpdatePayload($this->actingAs($user)->get(route($route, $company))->assertOk());

    postPageUpdate($payload)->assertOk()->assertJsonStructure(['components' => [['snapshot', 'effects']]]);
})->with([
    '経理' => [Role::Editor, false, 'company.entries.index'],
    '従業員' => [Role::Editor, true, 'company.submit'],
    '社長' => [Role::Owner, false, 'company.owner'],
]);

test('秘密の質問の答えは実際のHTTP通信でも決められて、その人の最初の画面へ移る', function (bool $submitOnly, string $route): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor, submitOnly: $submitOnly, withSecurityAnswer: false);
    $page = $this->actingAs($user)->get(route('security-answer.setup'))->assertOk();
    $payload = pageUpdatePayload($page, 'save', component: 'pages::setup.security-answer');
    $payload['components'][0]['updates']['answer'] = 'かくう';

    $saved = postPageUpdate($payload)->assertOk()->assertJsonPath('components.0.effects.redirect', route($route, $company));

    expect($user->fresh()->hasSecurityAnswer())->toBeTrue()
        ->and(collect($saved->headers->getCookies())->map->getName())->toContain('torudake_device');
})->with([
    '経理のホーム' => [false, 'company.home'],
    '従業員の送信画面' => [true, 'company.submit'],
]);

test('URLの絞り込みに配列を渡しても500にしない', function (string $route, string $parameter): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $this->actingAs($user)->get(route($route, ['company' => $company, $parameter => ['invalid']]))->assertOk();
})->with([
    '原資料' => ['company.sources.index', 'status'],
    '仕訳' => ['company.entries.index', 'status'],
    'AI記録' => ['company.ai', 'month'],
    '学習データ出力' => ['company.exports.training', 'month'],
]);
