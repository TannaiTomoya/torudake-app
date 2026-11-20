<?php

namespace App\Http\Responses;

use App\Support\MailSetup;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * 「パスワードを忘れた」でメールアドレスを送ったあとの応答（#39）。
 * 登録がある・ない・直前に送ったばかり・送信に失敗、のどれでも同じ文面を返す（登録の有無を外から調べられないように）。
 * メールを送れない設定のときは、「送りました」と言わない。
 */
class PasswordResetLinkRequested implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const SENT = '入力したメールアドレスが登録されていれば、パスワードを決め直すリンクを送りました。数分たっても届かないときは、迷惑メールを確かめるか、管理者に連絡してください。';

    public const NOT_DELIVERING = '今はメールを送れない設定です。パスワードを決め直すには、管理者に連絡してください。';

    // Fortify は結果（status）を渡してくるが、受け取らない。結果によって応答を変えないため

    public static function message(): string
    {
        return MailSetup::delivers() ? self::SENT : self::NOT_DELIVERING;
    }

    /**
     * {@inheritDoc}
     */
    public function toResponse($request)
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => self::message()], 200)
            : back()->with('status', self::message());
    }
}
