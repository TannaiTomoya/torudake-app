<?php

namespace App\Models;

use App\Enums\CorrectionReason;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $ai_run_id
 * @property string|null $journal_entry_id
 * @property string $field
 * @property string|null $before
 * @property string|null $after
 * @property CorrectionReason $reason
 * @property string|null $kernel
 * @property string|null $vendor
 * @property int $corrected_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiCorrection extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => CorrectionReason::class,
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
