<?php

namespace App\Services;

use App\Enums\SecurityAnswerResult;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 秘密の質問（docs/02 §5、#78）。答えはハッシュだけを持ち、答えそのものは保存もログも残さない。
 */
class SecurityAnswer
{
    public const QUESTION = 'いま、住んでいる町は？';

    public const HINT = '本当の町名でなくても、覚えていられる答えなら大丈夫です。';

    /** bcrypt が読むのは72バイトまで。日本語で24文字 */
    public const MAX_LENGTH = 24;

    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    /**
     * 書き方の違いで入れなくならないように、そろえてから比べる。
     * 全角と半角・カタカナとひらがな・大文字と小文字・空白・最後の「町・市・村」の違いは同じ答えにする。
     */
    public static function normalize(string $answer): string
    {
        $value = mb_convert_kana(mb_convert_kana($answer, 'asKV'), 'c');
        $value = mb_strtolower((string) preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $value));

        return mb_strlen($value) > 1 ? (string) preg_replace('/[町市村]$/u', '', $value) : $value;
    }

    /**
     * 答えを決める。$onlyIfUnset のときは、もう決めてある人のものは書き換えず false を返す。
     * 信頼していた端末はすべて忘れる（User の updated）。
     */
    public function set(User $user, string $answer, bool $onlyIfUnset = false): bool
    {
        $saved = DB::transaction(function () use ($user, $answer, $onlyIfUnset): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($onlyIfUnset && $locked->hasSecurityAnswer()) {
                return false;
            }

            $locked->forceFill([
                'security_answer_hash' => Hash::make(self::normalize($answer)),
                'security_answer_set_at' => now(),
                'security_answer_failed_attempts' => 0,
                'security_answer_locked_until' => null,
            ])->save();

            AuditLog::record(user: $locked, action: 'security_answer.set', subject: $locked);

            return true;
        });

        $user->refresh();

        return $saved;
    }

    /**
     * 答えが合っているか。5回まちがえたら15分止める。止めているあいだは、合っていても通さない。
     */
    public function verify(User $user, string $answer): SecurityAnswerResult
    {
        return DB::transaction(function () use ($user, $answer): SecurityAnswerResult {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->security_answer_locked_until?->isFuture() === true) {
                return SecurityAnswerResult::Locked;
            }

            if ($locked->security_answer_hash !== null && Hash::check(self::normalize($answer), $locked->security_answer_hash)) {
                $locked->forceFill(['security_answer_failed_attempts' => 0, 'security_answer_locked_until' => null])->save();

                return SecurityAnswerResult::Correct;
            }

            $failures = $locked->security_answer_failed_attempts + 1;
            $lock = $failures >= self::MAX_FAILURES;

            $locked->forceFill([
                'security_answer_failed_attempts' => $lock ? 0 : $failures,
                'security_answer_locked_until' => $lock ? now()->addMinutes(self::LOCK_MINUTES) : null,
            ])->save();

            AuditLog::record(user: $locked, action: $lock ? 'security_answer.lock' : 'security_answer.fail', subject: $locked);

            return $lock ? SecurityAnswerResult::Locked : SecurityAnswerResult::Wrong;
        });
    }

    /**
     * 忘れたとき（運営者のコマンドだけ）。答え・信頼した端末・ログイン中のセッションを消し、次のログインで決め直してもらう。
     */
    public function reset(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            $locked->forceFill([
                'security_answer_hash' => null,
                'security_answer_set_at' => null,
                'security_answer_failed_attempts' => 0,
                'security_answer_locked_until' => null,
                'remember_token' => Str::random(60),
            ])->save();

            app(TrustedDevices::class)->revokeAll($locked);

            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $locked->id)->delete();
            }

            AuditLog::record(user: null, action: 'security_answer.reset', subject: $locked);
        });

        $user->refresh();
    }
}
