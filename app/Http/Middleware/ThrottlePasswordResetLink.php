<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 「パスワードを忘れた」の送信回数を抑える（#39）。Fortify のルート全体に付け、送信のルートのときだけ働く。
 * 登録がある人にもない人にも同じように数えるので、登録の有無は分からない。
 */
class ThrottlePasswordResetLink
{
    /** 同じ接続元から、1分に何回まで */
    public const PER_IP_PER_MINUTE = 5;

    /** 同じメールアドレスあてに、1時間に何回まで */
    public const PER_EMAIL_PER_HOUR = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('password.email')) {
            return $next($request);
        }

        $email = Str::lower(trim((string) $request->input('email')));
        $limits = [
            ['password-reset-link:ip:'.$request->ip(), self::PER_IP_PER_MINUTE, 60],
            ['password-reset-link:email:'.sha1($email), self::PER_EMAIL_PER_HOUR, 3600],
        ];

        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => '回数が多すぎます。しばらく待ってから、もう一度お試しください。',
                ]);
            }
        }

        foreach ($limits as [$key, , $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        return $next($request);
    }
}
