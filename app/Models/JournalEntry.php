<?php

namespace App\Models;

use App\Enums\EntryKind;
use App\Enums\EntryStatus;
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
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $returned_reason
 * @property string|null $ai_run_id
 * @property array<string, mixed>|null $ai_check
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JournalEntry extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry): void {
            if ($entry->getOriginal('status') === EntryStatus::Approved) {
                throw new LogicException('承認済みの仕訳は変更できません。');
            }
        });

        static::deleting(function (JournalEntry $entry): void {
            if ($entry->getOriginal('status') === EntryStatus::Approved) {
                throw new LogicException('承認済みの仕訳は削除できません。');
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
            'approved_at' => 'datetime',
            'ai_check' => 'array',
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
     * @return BelongsTo<AiRun, $this>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }
}
