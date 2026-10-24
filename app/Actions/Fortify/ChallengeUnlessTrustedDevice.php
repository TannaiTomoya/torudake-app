<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\TrustedDevices;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\LoginRateLimiter;

/**
 * パスワードのあとに2段階認証のコードを聞く。信頼できる端末（TrustedDevices）からなら聞かずに進む。
 * それ以外は Fortify の RedirectIfTwoFactorAuthenticatable（confirm => true のとき）と同じ。
 */
class ChallengeUnlessTrustedDevice extends RedirectIfTwoFactorAuthenticatable
{
    public function __construct(StatefulGuard $guard, LoginRateLimiter $limiter, private readonly TrustedDevices $devices)
    {
        parent::__construct($guard, $limiter);
    }

    /**
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if (! $user instanceof User || $user->two_factor_secret === null || $user->two_factor_confirmed_at === null) {
            return $next($request);
        }

        return $this->devices->trusts($request, $user)
            ? $next($request)
            : $this->twoFactorChallengeResponse($request, $user);
    }
}
