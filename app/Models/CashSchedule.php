<?php

namespace App\Models;

use App\Enums\ScheduleCategory;
use App\Enums\ScheduleDirection;
use App\Services\CashScheduleService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 入金・支払いの予定（#67）。入れる・直す・済みにする・取り消すのは {@see CashScheduleService} だけ。
 * 金額は整数の円（1円以上）。済みの日（settled_on）がなく、予定日が今日より前なら「遅れ」（App\Support\ScheduleDelay）。
 *
 * @property string $id
 * @property string $company_id
 * @property ScheduleDirection $direction
 * @property ScheduleCategory $category
 * @property string|null $business_partner_id
 * @property string $bank_account_id
 * @property int $amount_yen
 * @property Carbon $due_date
 * @property Carbon|null $settled_on
 * @property int|null $settled_by
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BusinessPartner|null $partner
 * @property-read BankAccount $bankAccount
 */
class CashSchedule extends Model
{
    use HasUuids;

    public const INDIVIDUAL_LABEL = '個人のお客さん';

    public const NO_PARTNER_LABEL = '取引先なし';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => ScheduleDirection::class,
            'category' => ScheduleCategory::class,
            'amount_yen' => 'integer',
            'due_date' => 'date',
            'settled_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<BusinessPartner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'business_partner_id');
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * 画面に出す相手の名前。給料は相手を出さない（null）。取引先のない入金は「個人のお客さん」。
     * 予定の画面と社長の画面（どちらも manageSchedules の人だけ）のほかでは使わない。
     */
    public function partyLabel(): ?string
    {
        if (! $this->category->allowsPartner()) {
            return null;
        }

        if ($this->partner !== null) {
            return $this->partner->name;
        }

        return $this->direction === ScheduleDirection::In ? self::INDIVIDUAL_LABEL : self::NO_PARTNER_LABEL;
    }
}
