<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログインしたまま「パスワードを決める」リンクを開くと、何も言わずにホームへ飛んでいた（#80）。
 * 今ログインしている人を知らせて、「ログアウトして続ける」を出す。Fortify のルート全体に付け、その画面のときだけ働く。
 */
class OfferLogoutBeforePasswordReset
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('password.reset') || ! Auth::guard('web')->check()) {
            return $next($request);
        }

        return response()->view('pages::auth.password-reset-while-logged-in', [
            'currentUser' => Auth::guard('web')->user(),
            'token' => (string) $request->route('token'),
            'email' => $request->string('email')->toString(),
        ]);
    }
}
