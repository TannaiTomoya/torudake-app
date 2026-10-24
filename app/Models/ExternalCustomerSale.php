<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 取引先ごとの売上。取引先の名前は持たず、金額の大きい順に「A社」「B社」…と呼ぶ。
 *
 * @property string $id
 * @property string $company_id
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property int $rank
 * @property string $label
 * @property int $amount_yen
 * @property string|null $report_import_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExternalCustomerSale extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'rank' => 'integer',
            'amount_yen' => 'integer',
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
