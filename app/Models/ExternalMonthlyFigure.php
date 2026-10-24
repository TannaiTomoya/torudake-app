<?php

namespace App\Models;

use App\Enums\FigureMetric;
use App\Enums\FigureSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 財務王・販売王など外の月ごとの数字（今は売上高だけ）。
 *
 * @property string $id
 * @property string $company_id
 * @property string $year_month
 * @property FigureMetric $metric
 * @property int $amount_yen
 * @property FigureSource $source
 * @property string|null $report_import_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExternalMonthlyFigure extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => FigureMetric::class,
            'source' => FigureSource::class,
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
