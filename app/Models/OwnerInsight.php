<?php

namespace App\Models;

use App\Enums\InsightKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 社長へのひとこと・来月の見込み（AI）。入力のハッシュが前回と同じなら作り直さない。
 *
 * @property string $id
 * @property string $company_id
 * @property InsightKind $kind
 * @property string|null $input_hash
 * @property array<string, mixed>|null $output
 * @property string|null $ai_run_id
 * @property Carbon|null $generated_at
 * @property string|null $pending_hash
 * @property Carbon|null $pending_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OwnerInsight extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InsightKind::class,
            'output' => 'array',
            'generated_at' => 'datetime',
            'pending_at' => 'datetime',
        ];
    }

    public function isUpdating(): bool
    {
        return $this->pending_hash !== null;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
