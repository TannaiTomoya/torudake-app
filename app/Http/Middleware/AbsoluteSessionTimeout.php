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
        if (Auth::guard('web')->check() && $request->hasSession()) {
            $session = $request->session();
            $now = now()->getTimestamp();
            $loginAt = $session->get('auth.login_at');

            if (! is_int($loginAt)) {
                $session->put('auth.login_at', $now);
            } elseif (($now - $loginAt) >= self::MAX_LIFETIME_SECONDS) {
                Auth::guard('web')->logout();
                $session->invalidate();
                $session->regenerateToken();

                return redirect()->route('login');
            }
        }

        return $next($request);
    }
}
