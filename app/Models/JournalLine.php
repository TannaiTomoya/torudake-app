<?php

namespace App\Models;

use App\Enums\Side;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string $journal_entry_id
 * @property int $line_no
 * @property Side $side
 * @property string $account_id
 * @property int $amount_yen
 * @property string|null $tax_code
 * @property string|null $memo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JournalLine extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'side' => Side::class,
            'amount_yen' => 'integer',
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
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
