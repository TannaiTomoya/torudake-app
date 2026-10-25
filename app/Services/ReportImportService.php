<?php

namespace App\Services;

use App\Enums\FigureMetric;
use App\Enums\FigureSource;
use App\Enums\ReportStatus;
use App\Jobs\ProcessReportWithAi;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Evidence;
use App\Models\ExternalMonthlyFigure;
use App\Models\ReportImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 帳票の取り込み（7a）：写真を受け取って AI に読ませ、人が「保存」したときだけ月ごとの売上に入れる。
 */
class ReportImportService
{
    public function __construct(private readonly EvidenceFileStorage $files) {}

    public function upload(Company $company, User $user, UploadedFile $file): ReportImport
    {
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['photo' => '帳票はJPEG・PNG・WebPで送ってください。HEIC/HEIFは自動変換してから送ってください。']);
        }

        $path = $file->getRealPath();
        $sha256 = $path !== false ? hash_file('sha256', $path) : false;

        if ($sha256 === false) {
            throw new \RuntimeException('写真を読めませんでした。');
        }

        if ($company->evidences()->where('sha256', $sha256)->exists()) {
            throw ValidationException::withMessages(['photo' => 'この写真はもう送られています。']);
        }

        $fileName = Str::uuid7()->toString().'.'.($file->guessExtension() ?? 'bin');
        $storageKey = $this->files->put($company, $file, $fileName);
        $disk = Storage::disk((string) config('torudake.evidence.disk'));

        try {
            $import = DB::transaction(function () use ($company, $user, $file, $sha256, $mime, $storageKey): ReportImport {
                $evidence = new Evidence;
                $evidence->forceFill([
                    'company_id' => $company->id,
                    'storage_key' => $storageKey,
                    'sha256' => $sha256,
                    'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'uploaded_by' => $user->id,
                    'purge_after' => now()->addDays((int) config('torudake.evidence.retention_days')),
                ])->save();

                $import = new ReportImport;
                $import->forceFill([
                    'company_id' => $company->id,
                    'kind' => ReportImport::KIND_MONTHLY_SALES,
                    'evidence_id' => $evidence->id,
                    'status' => ReportStatus::Reading,
                    'created_by' => $user->id,
                ])->save();

                AuditLog::record(user: $user, action: 'report.upload', subject: $import, after: ['evidence_id' => $evidence->id], company: $company);

                return $import;
            });
        } catch (Throwable $e) {
            $disk->delete($storageKey);

            throw $e;
        }

        ProcessReportWithAi::dispatch($import->id);

        return $import;
    }

    /**
     * 読んだ数字を月ごとの売上に入れる。同じ月の数字は置き換える。
     */
    public function save(Company $company, User $user, string $importId): int
    {
        return DB::transaction(function () use ($company, $user, $importId): int {
            /** @var ReportImport $import */
            $import = $company->reportImports()->lockForUpdate()->findOrFail($importId);

            if ($import->status !== ReportStatus::Review) {
                $message = match ($import->status) {
                    ReportStatus::Saved => 'この取り込みはすでに保存されています。画面を読み直してください。',
                    ReportStatus::Reading => 'AIが読み取り中です。完了してから保存してください。',
                    default => '読み取りに失敗した取り込みは保存できません。写真を選び直してください。',
                };
                throw ValidationException::withMessages(['save' => $message]);
            }

            $before = [];
            foreach ($import->rows ?? [] as $row) {
                $figure = $company->externalMonthlyFigures()->lockForUpdate()
                    ->where('year_month', $row['year_month'])
                    ->where('metric', FigureMetric::Sales)
                    ->first() ?? new ExternalMonthlyFigure;

                $before[$row['year_month']] = $figure->exists ? $figure->amount_yen : null;

                $figure->forceFill([
                    'company_id' => $company->id,
                    'year_month' => $row['year_month'],
                    'metric' => FigureMetric::Sales,
                    'amount_yen' => $row['amount_yen'],
                    'source' => FigureSource::Hanbai,
                    'report_import_id' => $import->id,
                ])->save();
            }

            $import->forceFill(['status' => ReportStatus::Saved, 'saved_by' => $user->id, 'saved_at' => now()])->save();

            AuditLog::record(user: $user, action: 'report.save', subject: $import, before: $before, after: ['rows' => count($before)], company: $company);

            return count($before);
        });
    }
}
