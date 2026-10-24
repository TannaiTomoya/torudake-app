<?php

use App\Http\Responses\PasskeyLoginResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

function putPasskey(User $user, string $name): Passkey
{
    $passkey = new Passkey;
    $passkey->forceFill([
        'user_id' => $user->id,
        'name' => $name,
        'credential_id' => bin2hex(random_bytes(16)),
        'credential' => ['test' => true],
    ])->save();

    return $passkey;
}

test('顔でログイン（パスキー）を有効にし、ログイン画面にボタンを出す', function (): void {
    expect(Features::enabled(Features::passkeys()))->toBeTrue();

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('顔でログイン')
        ->assertSeeHtml('data-test="passkey-login"');
});

test('ログイン前の人が、顔でログインの準備（チャレンジ）を受け取れる。本人確認は必須', function (): void {
    $this->getJson(route('passkey.login-options'))
        ->assertOk()
        ->assertJsonPath('options.userVerification', 'required')
        ->assertJsonStructure(['options' => ['challenge']]);
});

test('顔でログインのあとも、ログイン前の遷移先ではなく役割別の振り分け（dashboard）へ行く', function (): void {
    $request = Request::create('/passkeys/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('url.intended', url('/c/someone-else/owner'));

    $response = app(PasskeyLoginResponse::class)->toResponse($request);

    expect($response->getData(true)['redirect'])->toBe(url('/dashboard'));
});

test('設定の画面で自分の登録を消せる。ほかの人の登録は消せない（404）', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $other = User::factory()->withTwoFactor()->create();
    $mine = putPasskey($user, '社長のスマホ');
    $others = putPasskey($other, '別の人のスマホ');

    Livewire::actingAs($user)->test('pages::settings.security')
        ->assertSee('社長のスマホ')
        ->assertDontSee('別の人のスマホ')
        ->call('deletePasskey', $others->id)
        ->assertNotFound();

    expect(Passkey::query()->whereKey($others->id)->exists())->toBeTrue();

    Livewire::actingAs($user)->test('pages::settings.security')
        ->call('deletePasskey', $mine->id)
        ->assertHasNoErrors()
        ->assertDontSee('社長のスマホ');

    expect(Passkey::query()->whereKey($mine->id)->exists())->toBeFalse();
});
