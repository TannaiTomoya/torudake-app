<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $storage_key
 * @property string $sha256
 * @property string $mime
 * @property int $size_bytes
 * @property int $uploaded_by
 * @property string|null $extracted_text
 * @property Carbon $purge_after
 * @property Carbon|null $purged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Evidence extends Model
{
    use HasUuids;

    protected $table = 'evidences';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'purge_after' => 'datetime',
            'purged_at' => 'datetime',
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasOne<SourceRecord, $this>
     */
    public function sourceRecord(): HasOne
    {
        return $this->hasOne(SourceRecord::class);
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null || $this->storage_key === null;
    }

    public function isImage(): bool
    {
        return in_array($this->mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }
}
