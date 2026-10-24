<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property int $fiscal_year_end_month
 * @property Carbon|null $bookkeeping_start_date
 * @property Carbon|null $closed_through
 * @property bool $allow_self_approval
 * @property bool $is_demo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'fiscal_year_end_month', 'bookkeeping_start_date', 'allow_self_approval', 'is_demo'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year_end_month' => 'integer',
            'bookkeeping_start_date' => 'date',
            'closed_through' => 'date',
            'allow_self_approval' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot(['id', 'role', 'submit_only', 'disabled_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<BankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * @return HasMany<Evidence, $this>
     */
    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    /**
     * @return HasMany<SourceRecord, $this>
     */
    public function sourceRecords(): HasMany
    {
        return $this->hasMany(SourceRecord::class);
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return HasMany<AiRun, $this>
     */
    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /**
     * @return HasMany<AiCorrection, $this>
     */
    public function aiCorrections(): HasMany
    {
        return $this->hasMany(AiCorrection::class);
    }
}
