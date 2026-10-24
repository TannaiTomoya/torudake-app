<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string|null $company_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $ip
 * @property Carbon|null $created_at
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'token',
        'secret',
        'api_key',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('監査記録は変更できません。');
        });

        static::deleting(function (): void {
            throw new LogicException('監査記録は削除できません。');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        ?User $user,
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?Company $company = null,
    ): self {
        $resolvedCompanyId = $company?->id;

        if ($resolvedCompanyId === null && $subject !== null) {
            if ($subject instanceof Company) {
                $resolvedCompanyId = $subject->id;
            } else {
                $attr = $subject->getAttribute('company_id');
                if (is_string($attr) && $attr !== '') {
                    $resolvedCompanyId = $attr;
                }
            }
        }

        $log = new self;
        $log->forceFill([
            'company_id' => $resolvedCompanyId,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject !== null ? (string) $subject->getKey() : null,
            'before' => self::scrub($before),
            'after' => self::scrub($after),
            'ip' => app()->runningInConsole() ? 'cli' : request()->ip(),
            'created_at' => now(),
        ])->save();

        return $log;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private static function scrub(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $clean = [];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $clean;
    }
}
