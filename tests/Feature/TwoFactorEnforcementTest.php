<?php

use App\Enums\Role;
use App\Http\Middleware\EnsureCompanyMember;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\User;
use App\Policies\CompanyPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportComputed\CannotCallComputedDirectlyException;
use Livewire\Livewire;

/**
 * 画面を開いて、Livewire の部品（snapshot）を受け取る。
 */
function pageSnapshot(User $user, string $url): string
{
    $html = test()->actingAs($user)->get($url)->assertOk()->getContent();

    return htmlspecialchars_decode(Str::betweenFirst((string) $html, 'wire:snapshot="', '"'));
}

/**
 * 受け取った部品で、本物の更新のリクエスト（$refresh）を送る。1つのテストで1回だけ使う
 * （Livewire は、同じ画面から続けて来た更新では確かめ直しを省くため）。
 */
function livewireRefresh(User $user, string $snapshot): TestResponse
{
    return test()->actingAs($user)
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => new stdClass,
                'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => new stdClass]],
            ]],
        ]);
}

test('T32 2段階認証がまだの人は、送る画面を開けてカードが出る。写真を選ぶ・送るは 403 で、原資料もファイルも増えない', function (bool $submitOnly): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor, submitOnly: $submitOnly, withTwoFactor: false);

    Livewire::actingAs($user)->test('pages::company.submit', ['company' => $company])
        ->assertSeeHtml('data-test="two-factor-first"')
        ->set('photo', UploadedFile::fake()->image('receipt.jpg'))
        ->assertForbidden();

    Livewire::actingAs($user)->test('pages::company.submit', ['company' => $company])
        ->set('photos', [UploadedFile::fake()->image('receipt.jpg')])
        ->call('send')
        ->assertForbidden();

    expect($company->sourceRecords()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('companies'))->toBe([]);
})->with(['送るだけの人' => true, '経理' => false]);

test('T32 2段階認証がまだの人は、カードを出す画面を開く（member・viewHome・viewSubmit）以外の権限をすべて拒まれる。済んだ人は今までどおり', function (): void {
    ['company' => $company, 'user' => $before] = createCompanyWithMember(Role::Owner, withTwoFactor: false);
    ['user' => $after] = createCompanyWithMember(Role::Owner, company: $company);

    $abilities = collect((new ReflectionClass(CompanyPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => $name === 'before');

    expect($abilities)->toContain('submit', 'editDraft', 'returnToBookkeeper', 'manageSettings', 'importReports');

    foreach ($abilities as $ability) {
        $allowedBefore = in_array($ability, ['member', 'viewHome', 'viewSubmit'], true);

        expect(Gate::forUser($before)->allows($ability, $company))->toBe($allowedBefore, $ability)
            ->and(Gate::forUser($after)->allows($ability, $company))->toBeTrue($ability);
    }
});

test('T32 ホームの件数（remaining・concerns）は、画面の外から呼べない', function (string $method): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner, withTwoFactor: false);

    expect(fn () => Livewire::actingAs($user)->test('pages::company.home', ['company' => $company])->call($method))
        ->toThrow(CannotCallComputedDirectlyException::class);
})->with(['remaining', 'concerns']);

test('T32 Livewire の更新のたびに、会社のメンバーか・2段階認証が済んだかを確かめ直す', function (): void {
    expect(Livewire::getPersistentMiddleware())->toContain(EnsureCompanyMember::class, EnsureTwoFactorEnabled::class);
});

test('T32 2段階認証が済んだ会社のメンバーは、今までどおり更新できる', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);

    livewireRefresh($user, pageSnapshot($user, route('company.ai', $company)))->assertOk();
});

test('T32 画面を開いたまま2段階認証を切った人は、次の更新でホームへ戻される', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $snapshot = pageSnapshot($user, route('company.ai', $company));

    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    livewireRefresh($user, $snapshot)->assertRedirect(route('company.home', $company));
});

test('T32 画面を開いたまま会社から外された人は、次の更新で 404 になる', function (): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Owner);
    $snapshot = pageSnapshot($user, route('company.ai', $company));

    $membership->forceFill(['disabled_at' => now()])->save();

    livewireRefresh($user, $snapshot)->assertNotFound();
});
