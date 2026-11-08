<?php

namespace App\Models;

use App\Enums\DuplicateDecision;
use App\Enums\DuplicateStrength;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string $source_record_id
 * @property string|null $target_entry_id
 * @property string $candidate_entry_id
 * @property DuplicateDecision $decision
 * @property DuplicateStrength $strength
 * @property list<string>|null $reasons
 * @property string|null $reason
 * @property string $target_fingerprint
 * @property string $candidate_fingerprint
 * @property int $decided_by
 * @property Carbon $decided_at
 */
class DuplicateReview extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => DuplicateDecision::class,
            'strength' => DuplicateStrength::class,
            'reasons' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
