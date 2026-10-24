<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 帳票の取り込み。AI が読んだ月ごとの売上（rows）は下書きで、経理・社長が「保存」したときだけ external_monthly_figures に入る。
 *
 * @property string $id
 * @property string $company_id
 * @property string $kind
 * @property string|null $evidence_id
 * @property string|null $ai_run_id
 * @property ReportStatus $status
 * @property list<array{year_month: string, amount_yen: int, confidence: float}>|null $rows
 * @property int|null $created_by
 * @property int|null $saved_by
 * @property Carbon|null $saved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReportImport extends Model
{
    use HasUuids;

    public const KIND_MONTHLY_SALES = 'monthly_sales';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'rows' => 'array',
            'saved_at' => 'datetime',
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
     * @return BelongsTo<Evidence, $this>
     */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }
}
