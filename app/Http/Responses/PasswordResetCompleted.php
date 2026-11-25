<?php

namespace App\Http\Responses;

use App\Http\Controllers\SecurityQuestionController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\PasswordResetResponse;

/**
 * パスワードを決めたら、ログイン画面に戻さずに先へ進める（#80）。
 * 秘密の質問の答えを決めてある人は、答えてからログインになる（メールを見られるだけでは入れない）。
 * まだの人はそのまま入り、ホームの前の「答えを決める画面」に着く。
 */
class PasswordResetCompleted implements PasswordResetResponse
{
    public function __construct(private readonly string $status) {}

    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => trans($this->status)], 200);
        }

        // ここに来るのは、リンクの合言葉とメールアドレスの組が合って、パスワードを書き換えたあとだけ
        /** @var User $user */
        $user = User::query()->where('email', $request->string('email')->toString())->firstOrFail();
        $remember = $request->boolean('remember');

        $request->session()->forget('url.intended');

        if ($user->hasSecurityAnswer()) {
            SecurityQuestionController::begin($request, $user, $remember);

            return redirect()->route('security-question.show');
        }

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
