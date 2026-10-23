<?php

// T01：会員登録の画面はない（docs/03 §8・04）。アカウントは owner がコマンドで作る

test('会員登録の画面は 404', function () {
    $this->get('/register')->assertNotFound();
});

test('会員登録の送信も受け付けない', function () {
    $this->post('/register', [
        'name' => 'だれか',
        'email' => 'someone@example.com',
        'password' => 'password-password',
        'password_confirmation' => 'password-password',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
});
