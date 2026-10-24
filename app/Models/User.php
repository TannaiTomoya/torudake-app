<?php

namespace App\Models;

use App\Services\TrustedDevices;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** 最後に開いた会社の ID（EnsureCompanyMember が入れる） */
    public const CURRENT_COMPANY_SESSION_KEY = 'current_company_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        // パスワードを変えた・2段階認証を切った（作り直した）ら、信頼した端末はすべて忘れる
        static::updated(function (User $user): void {
            if ($user->wasChanged(['password', 'two_factor_secret'])) {
                app(TrustedDevices::class)->revokeAll($user);
            }
        });
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<TrustedDevice, $this>
     */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'memberships')
            ->withPivot(['id', 'role', 'submit_only', 'disabled_at'])
            ->withTimestamps();
    }

    public function activeMembershipFor(Company $company): ?Membership
    {
        return $this->memberships()
            ->where('company_id', $company->id)
            ->whereNull('disabled_at')
            ->first();
    }

    public function primaryActiveMembership(): ?Membership
    {
        return $this->memberships()
            ->with('company')
            ->whereNull('disabled_at')
            ->oldest('created_at')
            ->first();
    }

    /**
     * 今の会社（最後に開いた会社の画面）に入っていればその会社、なければ最初に入った会社。
     */
    public function homeMembership(): ?Membership
    {
        $currentId = session(self::CURRENT_COMPANY_SESSION_KEY);

        $current = is_string($currentId)
            ? $this->memberships()->with('company')->where('company_id', $currentId)->whereNull('disabled_at')->first()
            : null;

        $membership = $current ?? $this->primaryActiveMembership();

        return $membership?->company !== null ? $membership : null;
    }

    /**
     * その人の最初の画面。送るだけの人は送る画面、ほかの人は会社のホーム。会社がなければ dashboard。
     * ログインのあと・設定の「戻る」・2段階認証を終えたあとは、必ずここを使う。
     */
    public function homeUrl(): string
    {
        $membership = $this->homeMembership();

        if ($membership === null || $membership->company === null) {
            return route('dashboard');
        }

        return $membership->submit_only
            ? route('company.submit', $membership->company)
            : route('company.home', $membership->company);
    }
}
