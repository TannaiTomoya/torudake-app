<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 会社に1冊の Excel（03 §2.3）。
 *
 * @property string $id
 * @property string $company_id
 * @property string $storage_key
 * @property list<string> $layout
 * @property int $next_row
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Workbook extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'next_row' => 'integer',
            'version' => 'integer',
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
