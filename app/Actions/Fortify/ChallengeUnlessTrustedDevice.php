<?php

namespace App\Actions\Fortify;

use App\Http\Controllers\SecurityQuestionController;
use App\Models\User;
use App\Services\TrustedDevices;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\LoginRateLimiter;

/**
 * パスワードのあとに秘密の質問を聞く。信頼できる端末（TrustedDevices）からなら聞かずに進む。
 * 答えをまだ決めていない人はそのまま入り、ホームの前の画面で決める。
 * パスワードの確かめ方は Fortify の RedirectIfTwoFactorAuthenticatable と同じ。
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

        if (! $user instanceof User || ! $user->hasSecurityAnswer() || $this->devices->trusts($request, $user)) {
            return $next($request);
        }

        // ここではまだログインさせない。答えが合ったら SecurityQuestionController がログインさせる
        $request->session()->put(SecurityQuestionController::PENDING_KEY, [
            'user_id' => $user->id,
            'password' => hash('sha256', $user->password),
            'remember' => $request->boolean('remember'),
            'expires_at' => now()->addMinutes(SecurityQuestionController::PENDING_MINUTES)->getTimestamp(),
        ]);

        return $request->wantsJson()
            ? response()->json(['two_factor' => true])
            : redirect()->route('security-question.show');
    }
}
