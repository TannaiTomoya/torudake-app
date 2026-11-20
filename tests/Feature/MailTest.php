<?php

use App\Http\Middleware\ThrottlePasswordResetLink;
use App\Http\Responses\PasswordResetLinkRequested;
use App\Mail\DeliveryTestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Support\MailSetup;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Mockery\MockInterface;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * T56：メールを送る設定・案内のメール・「パスワードを忘れた」（#39）。
 * 本物のメールは送らない（通知・メールは偽物に差し替える）。届くかどうかは本人が本番で確かめる（runbook「メールを送る」）。
 */
const MAIL_SECRET = 'secret-key-123';

/** 本番らしい「送られる」設定。宛先のサーバーは実在しない名前（.invalid）にして、万一でも外に出ないようにする */
function deliveringMail(): void
{
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.mail-service.invalid',
        'mail.mailers.smtp.port' => 465,
        'mail.mailers.smtp.username' => 'sender',
        'mail.mailers.smtp.password' => MAIL_SECRET,
        'mail.mailers.smtp.url' => null,
        'mail.from.address' => 'no-reply@torudake-app.com',
        'mail.from.name' => '撮って送るだけ帳簿',
        'app.url' => 'https://torudake-app.com',
    ]);
}

/** メールを送ろうとすると、送信サービスにつながらない */
function mailServiceDown(): void
{
    test()->mock(Dispatcher::class, fn (MockInterface $mock) => $mock->shouldReceive('send')
        ->andThrow(new TransportException('Failed to authenticate with password "'.MAIL_SECRET.'" ('.base64_encode(MAIL_SECRET).')')));
}

/**
 * @return array{int, string}
 */
function runCommand(string $command, array $arguments = []): array
{
    $exit = Artisan::call($command, $arguments);

    return [$exit, Artisan::output()];
}

function addMemberArguments(Company $company, string $email = 'new@example.com'): array
{
    return ['company' => $company->id, 'email' => $email, 'role' => 'editor', '--name' => '経理担当'];
}

// ---- メンバーの追加と案内のメール ----

test('メンバーの追加：送られる設定なら、追加して「送信しました」と出る', function (): void {
    deliveringMail();
    Notification::fake();
    $company = createCompanyWithMember()['company'];

    [$exit, $output] = runCommand('torudake:add-member', addMemberArguments($company));

    $user = User::query()->where('email', 'new@example.com')->firstOrFail();
    expect($exit)->toBe(0)
        ->and($output)->toContain('メンバーを追加しました')->toContain('パスワード再設定メールを送信しました');
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('メンバーの追加：送らずに書き出すだけの設定（log・array）では、「送信しました」と出さず、リンクの作り方を案内する', function (string $mailer): void {
    config(['mail.default' => $mailer]);
    Notification::fake();
    $company = createCompanyWithMember()['company'];

    [$exit, $output] = runCommand('torudake:add-member', addMemberArguments($company));

    expect($exit)->toBe(0)
        ->and($output)->toContain('メンバーを追加しました')
        ->toContain("メールは送られていません（今の設定 MAIL_MAILER={$mailer}")
        ->toContain('パスワードを決めるリンク')
        ->not->toContain('送信しました');
    expect(User::query()->where('email', 'new@example.com')->exists())->toBeTrue();
})->with(['log', 'array']);

test('メンバーの追加：直前に送ったばかりなら、送っていないと出して失敗にする。人も所属も増えない', function (): void {
    deliveringMail();
    Notification::fake();
    $company = createCompanyWithMember()['company'];
    runCommand('torudake:add-member', addMemberArguments($company));

    [$exit, $output] = runCommand('torudake:add-member', addMemberArguments($company));

    $user = User::query()->where('email', 'new@example.com')->firstOrFail();
    expect($exit)->toBe(1)
        ->and($output)->toContain('メールは送っていません')
        ->toContain('torudake:send-setup-link new@example.com')
        ->toContain('メンバーは追加済みです')
        ->not->toContain('送信しました');
    expect(User::query()->where('email', 'new@example.com')->count())->toBe(1)
        ->and(Membership::query()->where('user_id', $user->id)->count())->toBe(1);
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('メンバーの追加：メールを送れなければ失敗にする。所属は残り、画面にもログにも秘密の値を出さない', function (): void {
    deliveringMail();
    mailServiceDown();
    Log::spy();
    $company = createCompanyWithMember()['company'];

    [$exit, $output] = runCommand('torudake:add-member', addMemberArguments($company));

    $user = User::query()->where('email', 'new@example.com')->firstOrFail();
    expect($exit)->toBe(1)
        ->and($output)->toContain('メンバーを追加しました')
        ->toContain('メールを送れませんでした')
        ->toContain('メンバーは追加済みです')
        ->not->toContain('送信しました')
        ->not->toContain(MAIL_SECRET);
    expect(Membership::query()->where('user_id', $user->id)->where('company_id', $company->id)->count())->toBe(1);

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        $logged = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

        return str_contains($message, 'メールを送れませんでした')
            && $context['exception'] === TransportException::class
            && ! str_contains($logged, MAIL_SECRET)
            && ! str_contains($logged, base64_encode(MAIL_SECRET))
            && ! str_contains($logged, 'new@example.com');
    });
});

// ---- 送り直し ----

test('送り直し：すでにいる人に送る。所属・役割・監査の記録は変えない', function (): void {
    deliveringMail();
    Notification::fake();
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember();
    $logs = AuditLog::query()->count();

    [$exit, $output] = runCommand('torudake:send-setup-link', ['email' => strtoupper($user->email)]);

    expect($exit)->toBe(0)->and($output)->toContain('パスワード再設定メールを送信しました');
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    expect(Membership::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($membership->refresh()->role)->toBe($membership->role)
        ->and(AuditLog::query()->count())->toBe($logs);
});

test('送り直し：続けては送らない（1分は失敗）。1分たてば送れる', function (): void {
    deliveringMail();
    Notification::fake();
    $user = createCompanyWithMember()['user'];
    runCommand('torudake:send-setup-link', ['email' => $user->email]);

    [$exit, $output] = runCommand('torudake:send-setup-link', ['email' => $user->email]);
    expect($exit)->toBe(1)->and($output)->toContain('メールは送っていません')->not->toContain('送信しました');

    $this->travel(61)->seconds();
    [$exit] = runCommand('torudake:send-setup-link', ['email' => $user->email]);

    expect($exit)->toBe(0);
    Notification::assertSentToTimes($user, ResetPassword::class, 2);
});

test('送り直し：いない人には送らず失敗。送れない設定・送信の失敗でも「送信しました」と出ない', function (): void {
    deliveringMail();
    Notification::fake();
    [$exit, $output] = runCommand('torudake:send-setup-link', ['email' => 'nobody@example.com']);
    expect($exit)->toBe(1)->and($output)->toContain('見つかりません');
    Notification::assertNothingSent();

    $user = createCompanyWithMember()['user'];
    config(['mail.default' => 'log']);
    [$exit, $output] = runCommand('torudake:send-setup-link', ['email' => $user->email]);
    expect($exit)->toBe(0)->and($output)->toContain('メールは送られていません')->not->toContain('送信しました');

    deliveringMail();
    mailServiceDown();
    $this->travel(61)->seconds();
    [$exit, $output] = runCommand('torudake:send-setup-link', ['email' => $user->email]);
    expect($exit)->toBe(1)->and($output)->toContain('メールを送れませんでした')->not->toContain('送信しました');
});

// ---- 設定の確認と試し送り ----

test('設定の確認：本番らしい設定なら通り、「設定を見ただけ」と断る。秘密の値は出さない', function (): void {
    deliveringMail();

    [$exit, $output] = runCommand('torudake:mail-check');

    expect($exit)->toBe(0)
        ->and($output)->toContain('送り方：smtp（smtp.mail-service.invalid:465）')
        ->toContain('ユーザー名：あり／パスワード：あり')
        ->toContain('送信元：no-reply@torudake-app.com')
        ->toContain('リンクの先頭：https://torudake-app.com')
        ->toContain('設定の確認：問題は見つかりません')
        ->toContain('これは設定を見ただけです')
        ->not->toContain(MAIL_SECRET)
        ->not->toContain('送信サービスに渡しました');
});

test('設定の確認：送られない・開発用・仮の値を見つけて失敗にする', function (array $override, string $expected): void {
    deliveringMail();
    config($override);

    [$exit, $output] = runCommand('torudake:mail-check', ['--to' => 'me@example.com']);

    expect($exit)->toBe(1)
        ->and($output)->toContain("NG {$expected}")
        ->toContain('直すところが 1 件')
        ->not->toContain('送信サービスに渡しました')
        ->not->toContain(MAIL_SECRET);
})->with([
    'log' => [['mail.default' => 'log'], 'メールは送られません（MAIL_MAILER=log'],
    'array' => [['mail.default' => 'array'], 'メールは送られません（MAIL_MAILER=array'],
    '開発用の受け皿（Mailpit）' => [['mail.mailers.smtp.host' => 'mailpit'], '送り先のサーバー（MAIL_HOST）が開発用の受け皿です：mailpit'],
    '自分のサーバー' => [['mail.mailers.smtp.host' => '127.0.0.1'], '送り先のサーバー（MAIL_HOST）が開発用の受け皿です：127.0.0.1'],
    'パスワードが空' => [['mail.mailers.smtp.password' => null], 'MAIL_USERNAME か MAIL_PASSWORD が空です'],
    'ユーザー名が空' => [['mail.mailers.smtp.username' => ''], 'MAIL_USERNAME か MAIL_PASSWORD が空です'],
    '送信元が仮' => [['mail.from.address' => 'hello@example.com'], '送信元（MAIL_FROM_ADDRESS）が仮の値です：hello@example.com'],
    '送信元がメールの形でない' => [['mail.from.address' => 'torudake'], '送信元（MAIL_FROM_ADDRESS）が仮の値です：torudake'],
    'リンクが http' => [['app.url' => 'http://torudake-app.com'], 'メールの中のリンクの先頭（APP_URL）が、https の公開の URL ではありません：http://torudake-app.com'],
    'リンクが手元' => [['app.url' => 'https://localhost'], 'メールの中のリンクの先頭（APP_URL）が、https の公開の URL ではありません：https://localhost'],
]);

test('試し送り：指定した宛先に1通送り、「渡した」と「届いた」を分けて伝える', function (): void {
    deliveringMail();
    Mail::fake();

    [$exit, $output] = runCommand('torudake:mail-check', ['--to' => 'me@example.com']);

    expect($exit)->toBe(0)
        ->and($output)->toContain('試し送り：送信サービスに渡しました（me@example.com）')
        ->toContain('まだ「届いた」ではありません');
    Mail::assertSent(DeliveryTestMail::class, fn (DeliveryTestMail $mail): bool => $mail->hasTo('me@example.com'));
    Mail::assertSentCount(1);
});

test('試し送り：本文は試し送りだと分かり、ログインの場所だけを載せる', function (): void {
    deliveringMail();

    $mail = new DeliveryTestMail;

    $mail->assertHasSubject('【'.config('app.name').'】メールの試し送り');
    $mail->assertSeeInText('試し送りです');
    $mail->assertSeeInText(route('login'));
});

test('試し送り：送れなければ失敗にし、秘密の値は出さない', function (): void {
    deliveringMail();
    Mail::shouldReceive('to->send')->once()->andThrow(new TransportException('Connection refused with password "'.MAIL_SECRET.'"'));

    [$exit, $output] = runCommand('torudake:mail-check', ['--to' => 'me@example.com']);

    expect($exit)->toBe(1)
        ->and($output)->toContain('試し送り：送れませんでした')
        ->toContain('Connection refused')
        ->not->toContain(MAIL_SECRET)
        ->not->toContain('送信サービスに渡しました');
});

test('試し送り：宛先がメールの形でなければ送らない', function (): void {
    deliveringMail();
    Mail::fake();

    [$exit, $output] = runCommand('torudake:mail-check', ['--to' => 'not-an-address']);

    expect($exit)->toBe(1)->and($output)->toContain('メールアドレスの形ではありません');
    Mail::assertNothingSent();
});

test('秘密の値の伏せ方：パスワードそのもの・base64・URL 用の形を消し、長い文面は切る', function (): void {
    deliveringMail();
    config(['mail.mailers.smtp.password' => 'p@ss word/1']);

    $scrubbed = MailSetup::scrub('a p@ss word/1 b '.base64_encode('p@ss word/1').' c '.rawurlencode('p@ss word/1').' '.str_repeat('x', 500));

    expect($scrubbed)->not->toContain('p@ss word/1')
        ->not->toContain(base64_encode('p@ss word/1'))
        ->not->toContain(rawurlencode('p@ss word/1'))
        ->toContain('（伏せた）')
        ->and(mb_strlen($scrubbed))->toBeLessThanOrEqual(303);
});

// ---- 「パスワードを忘れた」の画面 ----

test('パスワードを忘れた：登録がある・ない・直前に送ったばかり、のどれでも同じ応答（登録の有無が分からない）', function (): void {
    deliveringMail();
    Notification::fake();
    $user = User::factory()->create();

    $responses = [
        'ある' => $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]),
        'ない' => $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.com']),
        '直前に送った' => $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]),
    ];

    foreach ($responses as $response) {
        $response->assertRedirect(route('password.request'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', PasswordResetLinkRequested::SENT);
    }

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
});

test('パスワードを忘れた：JSON でも、登録がある・ないで同じ応答', function (): void {
    deliveringMail();
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->postJson(route('password.email'), ['email' => $user->email]);
    $unknown = $this->postJson(route('password.email'), ['email' => 'nobody@example.com']);

    $known->assertOk()->assertExactJson(['message' => PasswordResetLinkRequested::SENT]);
    $unknown->assertOk()->assertExactJson(['message' => PasswordResetLinkRequested::SENT]);
});

test('パスワードを忘れた：送信サービスにつながらなくても、応答は同じ。送れなかったことはログに残す', function (): void {
    deliveringMail();
    mailServiceDown();
    Log::spy();
    $user = User::factory()->create();

    $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', PasswordResetLinkRequested::SENT);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'メールを送れませんでした（パスワードを忘れた）'))->once();
});

test('パスワードを忘れた：メールを送れない設定では、送れるように見せない', function (string $mailer): void {
    config(['mail.default' => $mailer]);
    Notification::fake();
    $user = User::factory()->create();

    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee(PasswordResetLinkRequested::NOT_DELIVERING)
        ->assertSee('data-test="mail-not-delivering"', false)
        ->assertDontSee('data-test="email-password-reset-link-button"', false);

    $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', PasswordResetLinkRequested::NOT_DELIVERING);
})->with(['log', 'array']);

test('パスワードを忘れた：送られる設定なら、入力欄が出る', function (): void {
    deliveringMail();

    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('data-test="email-password-reset-link-button"', false)
        ->assertDontSee('data-test="mail-not-delivering"', false);
});

test('パスワードを忘れた：同じ接続元からは1分に5回まで。登録がない宛先でも同じように数える。1分たてば送れる', function (): void {
    deliveringMail();
    Notification::fake();

    foreach (range(1, ThrottlePasswordResetLink::PER_IP_PER_MINUTE) as $i) {
        $this->post(route('password.email'), ['email' => "nobody{$i}@example.com"])->assertSessionHasNoErrors();
    }

    $user = User::factory()->create();
    $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasErrors(['email' => '回数が多すぎます。しばらく待ってから、もう一度お試しください。']);
    Notification::assertNothingSent();

    $this->travel(61)->seconds();
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('パスワードを忘れた：同じ宛先には、接続元を変えても1時間に5回まで（大文字・空白の違いは同じ宛先）', function (): void {
    deliveringMail();
    Notification::fake();

    foreach (range(1, ThrottlePasswordResetLink::PER_EMAIL_PER_HOUR) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->post(route('password.email'), ['email' => 'Target@Example.com'])->assertSessionHasNoErrors();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->post(route('password.email'), ['email' => ' target@example.com '])
        ->assertSessionHasErrors('email');

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->post(route('password.email'), ['email' => 'other@example.com'])
        ->assertSessionHasNoErrors();
});

test('回数の制限は、「パスワードを忘れた」の送信だけに働く（ログインの画面は数えない）', function (): void {
    foreach (range(1, ThrottlePasswordResetLink::PER_IP_PER_MINUTE + 3) as $i) {
        $this->get(route('login'))->assertOk();
        $this->get(route('password.request'))->assertOk();
    }
});

// ---- リンクの期限と使い回し ----

test('リンク：一度使ったら、もう使えない', function (): void {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    $form = ['token' => $token, 'email' => $user->email, 'password' => 'new-password-12345', 'password_confirmation' => 'new-password-12345'];

    $this->post(route('password.update'), $form)->assertSessionHasNoErrors();
    expect(Hash::check('new-password-12345', (string) $user->refresh()->password))->toBeTrue();

    $again = [...$form, 'password' => 'another-password-12345', 'password_confirmation' => 'another-password-12345'];
    $this->post(route('password.update'), $again)->assertSessionHasErrors('email');
    expect(Hash::check('new-password-12345', (string) $user->refresh()->password))->toBeTrue();
});

test('リンク：60分を過ぎたら使えない', function (): void {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    $form = ['token' => $token, 'email' => $user->email, 'password' => 'new-password-12345', 'password_confirmation' => 'new-password-12345'];

    $this->travel(61)->minutes();

    $this->post(route('password.update'), $form)->assertSessionHasErrors('email');
    expect(Hash::check('new-password-12345', (string) $user->refresh()->password))->toBeFalse()
        ->and(config('auth.passwords.users.expire'))->toBe(60);
});
