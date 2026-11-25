<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 秘密の質問の答えをまだ決めていない人は、ホームの前の「答えを決める画面」へ回す（docs/02 §5）。
 */
class EnsureSecurityAnswerSet
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        return $user->hasSecurityAnswer() ? $next($request) : redirect()->route('security-answer.setup');
    }
}
