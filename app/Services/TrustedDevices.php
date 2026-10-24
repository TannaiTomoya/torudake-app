<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * 信頼できる端末（docs/02 §5）。パスワード＋2段階認証で入った端末を30日覚え、その間はパスワードだけで入れる。
 * クッキーには「端末の ID|乱数」を入れ（Laravel が暗号化する）、DB には乱数のハッシュだけを置く。
 */
class TrustedDevices
{
    public const COOKIE = 'torudake_device';

    public const DAYS = 30;

    public function issue(Request $request, User $user): TrustedDevice
    {
        $token = Str::random(64);
        $label = mb_substr(trim((string) $request->userAgent()), 0, 200);

        $device = new TrustedDevice;
        $device->forceFill([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'label' => $label === '' ? null : $label,
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::DAYS),
        ])->save();

        Cookie::queue(Cookie::make(self::COOKIE, $device->id.'|'.$token, self::DAYS * 24 * 60, httpOnly: true, sameSite: 'lax'));

        AuditLog::record(user: $user, action: 'device.trust', subject: $device, after: ['expires_at' => $device->expires_at->toIso8601String()]);

        return $device;
    }

    /**
     * このリクエストのクッキーが、その人の期限内の端末か。合っていれば最後に使った日時を残す。
     */
    public function trusts(Request $request, User $user): bool
    {
        $value = $request->cookie(self::COOKIE);

        if (! is_string($value) || ! str_contains($value, '|')) {
            return false;
        }

        [$id, $token] = explode('|', $value, 2);

        /** @var TrustedDevice|null $device */
        $device = $user->trustedDevices()->whereKey($id)->where('expires_at', '>', now())->first();

        if ($device === null || ! hash_equals($device->token_hash, hash('sha256', $token))) {
            return false;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    public function count(User $user): int
    {
        return $user->trustedDevices()->where('expires_at', '>', now())->count();
    }

    /**
     * その人の端末をすべて忘れる（パスワードを変えた・2段階認証を切った・設定で「すべて解除」）。
     */
    public function revokeAll(User $user): int
    {
        $devices = $user->trustedDevices()->get();

        $devices->each(fn (TrustedDevice $device) => $device->delete());

        if ($devices->isNotEmpty()) {
            AuditLog::record(user: $user, action: 'device.revoke', subject: $user, after: ['count' => $devices->count()]);
        }

        return $devices->count();
    }
}
