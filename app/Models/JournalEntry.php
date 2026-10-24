<?php

namespace App\Models;

use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\Side;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $company_id
 * @property Carbon $entry_date
 * @property EntryKind $kind
 * @property EntryStatus $status
 * @property string|null $description
 * @property string|null $vendor
 * @property string|null $category_id
 * @property string|null $bank_account_id
 * @property int|null $created_by
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property int|null $returned_by
 * @property Carbon|null $returned_at
 * @property string|null $returned_reason
 * @property string|null $ai_run_id
 * @property array<string, mixed>|null $ai_check
 * @property list<string>|null $concern_flags
 * @property Carbon|null $owner_seen_at
 * @property int|null $workbook_row
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JournalEntry extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry): void {
            if ($entry->getOriginal('status') !== EntryStatus::Draft && $entry->status === EntryStatus::Draft) {
                throw new LogicException('確定した仕訳は下書きに戻せません。');
            }
        });

        static::deleting(function (JournalEntry $entry): void {
            if ($entry->getOriginal('status') !== EntryStatus::Draft) {
                throw new LogicException('確定した仕訳は削除できません。');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'kind' => EntryKind::class,
            'status' => EntryStatus::class,
            'confirmed_at' => 'datetime',
            'returned_at' => 'datetime',
            'owner_seen_at' => 'datetime',
            'ai_check' => 'array',
            'concern_flags' => 'array',
            'workbook_row' => 'integer',
        ];
    }

    /**
     * 借方の合計。行がない（金額の記載がない下書き）ときは null。
     */
    public function amountYen(): ?int
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        if ($lines->isEmpty()) {
            return null;
        }

        return (int) $lines->where('side', Side::Debit)->sum('amount_yen');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<SourceRecord, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(SourceRecord::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }
}
