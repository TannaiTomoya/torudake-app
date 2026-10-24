<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2段階認証がまだの人は、ホーム（送るだけの人は送る画面）だけ開ける。そこに「最初にやること」のカードが出る。
 */
class EnsureTwoFactorEnabled
{
    public const ALLOWED_ROUTES = ['company.home', 'company.submit'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->two_factor_confirmed_at === null && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return redirect()->to($user->homeUrl());
        }

        return $next($request);
    }
}
