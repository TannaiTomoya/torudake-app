<?php

namespace App\Models;

use App\Services\BankBalanceService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 通帳の残高（口座×日付で1件。#66）。入れる・直す・取り消すは {@see BankBalanceService} だけ。
 *
 * @property string $id
 * @property string $company_id
 * @property string $bank_account_id
 * @property Carbon $balance_date
 * @property int $balance_yen
 * @property string $source manual（手入力）／statement（明細CSV。E01）
 * @property int $entered_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BankAccount $bankAccount
 * @property-read User $enteredBy
 */
class BankBalance extends Model
{
    use HasUuids;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_STATEMENT = 'statement';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_date' => 'date',
            'balance_yen' => 'integer',
        ];
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
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
