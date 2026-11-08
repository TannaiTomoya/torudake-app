<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 経理が保存したときの、読み取り欄ごとの確認の記録。
 * method：confirmed（確かめた）・changed（人が値を変えた）・entered（AI が読めず、人が入れた）。
 *
 * @property string $id
 * @property string $company_id
 * @property string $journal_entry_id
 * @property string|null $ai_run_id
 * @property string $field
 * @property string $method
 * @property list<string>|null $reasons
 * @property string|null $value
 * @property int $confirmed_by
 * @property Carbon $confirmed_at
 */
class ReadingConfirmation extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
