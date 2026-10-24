<?php

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Str;

test('ログイン画面に「ログイン状態を保持する」を出さない', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('ログイン状態を保持する')
        ->assertDontSee('name="remember"', false);
});

test('ログイン状態の保持のクッキーだけで来たら、ログイン画面に戻してゲストにする', function (): void {
    $token = Str::random(60);
    $user = User::factory()->withTwoFactor()->create(['remember_token' => $token]);

    /** @var SessionGuard $guard */
    $guard = auth()->guard('web');

    $this->withCookie($guard->getRecallerName(), $user->id.'|'.$token.'|'.$user->password)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->fresh()?->remember_token)->not->toBe($token);
});

test('ログインから24時間たったセッションは切れる', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.login_at' => now()->subDay()->getTimestamp()])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('ログインから24時間たつ前は、そのまま使える', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.login_at' => now()->subHours(23)->getTimestamp()])
        ->get(route('dashboard'))
        ->assertOk();

    $this->assertAuthenticatedAs($user);
});
