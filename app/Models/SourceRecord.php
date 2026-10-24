<?php

namespace App\Models;

use App\Enums\AiStatus;
use App\Enums\SourceKind;
use App\Enums\SourceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property SourceKind $kind
 * @property SourceStatus $status
 * @property AiStatus $ai_status
 * @property string|null $ai_stage
 * @property string|null $ai_error
 * @property string $evidence_id
 * @property Carbon|null $occurred_on
 * @property int|null $amount_yen
 * @property string|null $vendor
 * @property string|null $note
 * @property bool $late
 * @property int $submitted_by
 * @property string|null $journal_entry_id
 * @property string|null $ignored_reason
 * @property int|null $ignored_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SourceRecord extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SourceKind::class,
            'status' => SourceStatus::class,
            'ai_status' => AiStatus::class,
            'occurred_on' => 'date',
            'amount_yen' => 'integer',
            'late' => 'boolean',
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
     * @return BelongsTo<Evidence, $this>
     */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
