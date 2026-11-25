<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 「ログイン状態を保持する」を選ばなかったログインは、24時間で必ず切る（docs/02 §5）。
 * 選んだログインは切らない（保持のクッキーが30日で切れる）。
 */
class AbsoluteSessionTimeout
{
    /** セッションに残す、保持を選んだログインかどうか（AppServiceProvider が Login のときに入れる） */
    public const REMEMBERED_KEY = 'auth.remembered';

    private const MAX_LIFETIME_SECONDS = 86400;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if ($guard->check() && $request->hasSession()) {
            $session = $request->session();

            if ($session->get(self::REMEMBERED_KEY) !== true) {
                $now = now()->getTimestamp();
                $loginAt = $session->get('auth.login_at');

                if (is_int($loginAt) && ($now - $loginAt) >= self::MAX_LIFETIME_SECONDS) {
                    $guard->logout();
                    $session->invalidate();
                    $session->regenerateToken();

                    return redirect()->route('login');
                }

                if (! is_int($loginAt)) {
                    $session->put('auth.login_at', $now);
                }
            }
        }

        return $next($request);
    }
}
