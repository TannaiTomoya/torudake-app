<?php

use App\Enums\Role;
use App\Http\Middleware\EnsureCompanyMember;
use App\Http\Middleware\EnsureSecurityAnswerSet;
use App\Models\User;
use App\Policies\CompanyPolicy;
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

test('T32 答えを決めていない人は、送る画面の操作を直接呼んでも 403 で、原資料もファイルも増えない', function (bool $submitOnly): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor, submitOnly: $submitOnly, withSecurityAnswer: false);

    Livewire::actingAs($user)->test('pages::company.submit', ['company' => $company])
        ->assertForbidden();

    expect($company->sourceRecords()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('companies'))->toBe([]);
})->with(['送るだけの人' => true, '経理' => false]);

test('T32 答えを決めていない人は、会社のメンバーかの確認（member）以外の権限をすべて拒まれる。決めた人は今までどおり', function (): void {
    ['company' => $company, 'user' => $before] = createCompanyWithMember(Role::Owner, withSecurityAnswer: false);
    ['user' => $after] = createCompanyWithMember(Role::Owner, company: $company);

    $abilities = collect((new ReflectionClass(CompanyPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => $name === 'before');

    expect($abilities)->toContain('submit', 'editDraft', 'returnToBookkeeper', 'manageSettings', 'importReports');

    foreach ($abilities as $ability) {
        expect(Gate::forUser($before)->allows($ability, $company))->toBe($ability === 'member', $ability)
            ->and(Gate::forUser($after)->allows($ability, $company))->toBeTrue($ability);
    }
});

test('T32 ホームの件数（remaining・concerns）は、画面の外から呼べない', function (string $method): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);

    expect(fn () => Livewire::actingAs($user)->test('pages::company.home', ['company' => $company])->call($method))
        ->toThrow(CannotCallComputedDirectlyException::class);
})->with(['remaining', 'concerns']);

test('T32 Livewire の更新のたびに、会社のメンバーか・秘密の質問を決めたかを確かめ直す', function (): void {
    expect(Livewire::getPersistentMiddleware())->toContain(EnsureCompanyMember::class, EnsureSecurityAnswerSet::class);
});

test('T32 秘密の質問を決めた会社のメンバーは、今までどおり更新できる', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);

    livewireRefresh($user, pageSnapshot($user, route('company.ai', $company)))->assertOk();
});

test('T32 画面を開いたまま秘密の質問の答えを消された人は、次の更新で答えを決める画面へ回る', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $snapshot = pageSnapshot($user, route('company.ai', $company));

    $user->forceFill(['security_answer_hash' => null, 'security_answer_set_at' => null])->save();

    livewireRefresh($user, $snapshot)->assertRedirect(route('security-answer.setup'));
});

test('T32 画面を開いたまま会社から外された人は、次の更新で 404 になる', function (): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Owner);
    $snapshot = pageSnapshot($user, route('company.ai', $company));

    $membership->forceFill(['disabled_at' => now()])->save();

    livewireRefresh($user, $snapshot)->assertNotFound();
});
