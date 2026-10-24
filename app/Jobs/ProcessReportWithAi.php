<?php

namespace App\Jobs;

use App\Enums\ReportStatus;
use App\Models\ReportImport;
use App\Services\Ai\AiException;
use App\Services\Ai\AiTaskRunner;
use App\Services\Ai\Tasks\ReadReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 帳票の写真を read_report で読み、月ごとの数字を下書き（rows）にする。保存は人。
 */
class ProcessReportWithAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public string $reportImportId) {}

    public function handle(AiTaskRunner $runner, ReadReport $readReport): void
    {
        $import = ReportImport::query()->with(['company', 'evidence'])->find($this->reportImportId);

        if ($import === null || $import->company === null || $import->evidence === null || $import->status !== ReportStatus::Reading) {
            return;
        }

        $evidence = $import->evidence;

        try {
            $runner->assertWithinDailyLimit($import->company);

            $contents = $evidence->storage_key !== null
                ? Storage::disk((string) config('torudake.evidence.disk'))->get($evidence->storage_key)
                : null;

            if ($contents === null) {
                throw new AiException('写真を読めませんでした。');
            }

            $outcome = $readReport->run($import->company, $import, $evidence->sha256, $evidence->mime, base64_encode($contents));

            $import->forceFill([
                'rows' => self::rows($outcome->output['rows'] ?? []),
                'ai_run_id' => $outcome->run->id,
                'status' => ReportStatus::Review,
            ])->save();
        } catch (Throwable $e) {
            $import->forceFill(['status' => ReportStatus::Failed])->save();

            report($e);
        }
    }

    /**
     * 形の正しい行だけを月の順に残す。同じ月が2回あれば後のほう。
     *
     * @return list<array{year_month: string, amount_yen: int, confidence: float}>
     */
    public static function rows(mixed $rows): array
    {
        $byMonth = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! is_string($row['year_month'] ?? null) || ! is_int($row['amount_yen'] ?? null)) {
                continue;
            }

            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $row['year_month']) !== 1 || $row['amount_yen'] < 0) {
                continue;
            }

            $byMonth[$row['year_month']] = [
                'year_month' => $row['year_month'],
                'amount_yen' => $row['amount_yen'],
                'confidence' => is_int($row['confidence'] ?? null) || is_float($row['confidence'] ?? null) ? (float) $row['confidence'] : 0.0,
            ];
        }

        ksort($byMonth);

        return array_values($byMonth);
    }
}
