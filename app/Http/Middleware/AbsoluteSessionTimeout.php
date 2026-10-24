<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AbsoluteSessionTimeout
{
    private const MAX_LIFETIME_SECONDS = 86400;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if ($guard->check() && $request->hasSession()) {
            $session = $request->session();
            $now = now()->getTimestamp();
            $loginAt = $session->get('auth.login_at');

            // ログイン状態の保持（remember のクッキー）では入れない。入り直すと24時間の数え直しになるため。
            $viaRemember = $guard->viaRemember();

            if ($viaRemember || (is_int($loginAt) && ($now - $loginAt) >= self::MAX_LIFETIME_SECONDS)) {
                $guard->logout();
                $session->invalidate();
                $session->regenerateToken();

                return redirect()->route('login');
            }

            if (! is_int($loginAt)) {
                $session->put('auth.login_at', $now);
            }
        }

        return $next($request);
    }
}
