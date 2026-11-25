<?php

namespace App\Http\Controllers;

use App\Enums\SecurityAnswerResult;
use App\Models\User;
use App\Services\SecurityAnswer;
use App\Services\TrustedDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ログインの2段階目（秘密の質問、docs/02 §5）。パスワードが合った人だけがここに来る。
 */
class SecurityQuestionController extends Controller
{
    public const PENDING_KEY = 'login.security_question';

    public const PENDING_MINUTES = 10;

    public function show(Request $request): View|RedirectResponse
    {
        return $this->pendingUser($request) === null
            ? redirect()->route('login')
            : view('pages::auth.security-question');
    }

    public function store(Request $request, SecurityAnswer $answers, TrustedDevices $devices): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $request->validate(
            ['answer' => ['required', 'string', 'max:'.SecurityAnswer::MAX_LENGTH]],
            attributes: ['answer' => '答え'],
        );

        $result = $answers->verify($user, $request->string('answer')->toString());

        if ($result !== SecurityAnswerResult::Correct) {
            throw ValidationException::withMessages(['answer' => $result === SecurityAnswerResult::Locked
                ? '何度もまちがえたので、'.SecurityAnswer::LOCK_MINUTES.'分ほど止めています。時間をおいて、もう一度ログインしてください。'
                : '答えが違います。']);
        }

        $remember = (bool) $request->session()->get(self::PENDING_KEY.'.remember', false);
        $request->session()->forget([self::PENDING_KEY, 'url.intended']);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        if ($request->boolean('trust_device')) {
            $devices->issue($request, $user);
        }

        return redirect()->route('dashboard');
    }

    /**
     * パスワードが合ってから10分以内で、そのあとパスワードが変わっていない人。
     */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::PENDING_KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->find($pending['user_id'] ?? null);

        if ($user === null || ! $user->hasSecurityAnswer() || ! hash_equals(hash('sha256', $user->password), (string) ($pending['password'] ?? ''))) {
            return null;
        }

        return $user;
    }
}
