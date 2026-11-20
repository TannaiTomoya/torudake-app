<?php

use App\Http\Middleware\AbsoluteSessionTimeout;
use App\Http\Middleware\EnsureCompanyMember;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Responses\PasswordResetLinkRequested;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AbsoluteSessionTimeout::class,
        ]);

        $middleware->alias([
            'company' => EnsureCompanyMember::class,
            'two-factor' => EnsureTwoFactorEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 「パスワードを忘れた」でメールを送れなかったとき（#39）。登録がある人のときだけエラー画面になると、
        // 登録の有無が外から分かってしまう。応答はいつもと同じにして、送れなかったことはログに残す
        $exceptions->render(function (TransportExceptionInterface $e, Request $request) {
            if (! $request->routeIs('password.email')) {
                return null;
            }

            Log::error('メールを送れませんでした（パスワードを忘れた）', ['exception' => $e::class]);

            return (new PasswordResetLinkRequested)->toResponse($request);
        });
    })->create();
