<?php

namespace App\Services;

use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\Side;
use App\Enums\SourceStatus;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SourceRecord;
use App\Services\Ai\AiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * L2 の出力から下書きの仕訳を作る。科目・分類は会社の中のものだけに対応させる。
 */
class AiDraftBuilder
{
    /**
     * @param  array<string, mixed>  $reading  L1 の出力
     * @param  array<string, mixed>  $draft  L2 の出力
     */
    public function build(Company $company, string $sourceId, array $reading, array $draft, AiRun $run): ?JournalEntry
    {
        return DB::transaction(function () use ($company, $sourceId, $reading, $draft, $run): ?JournalEntry {
            /** @var Company $lockedCompany */
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);

            /** @var SourceRecord $source */
            $source = $lockedCompany->sourceRecords()->lockForUpdate()->findOrFail($sourceId);

            if ($source->status !== SourceStatus::New || $source->journal_entry_id !== null) {
                return null;
            }

            $category = $lockedCompany->categories()
                ->whereNull('archived_at')
                ->where('name', is_string($draft['category'] ?? null) ? $draft['category'] : '')
                ->first();

            if ($category === null) {
                throw AiException::invalidOutput('分類が会社の一覧にありません');
            }

            $accountIdsByCode = $lockedCompany->accounts()
                ->whereNull('archived_at')
                ->pluck('id', 'code');

            /** @var list<array{side: Side, account_id: string, amount_yen: int}> $lines */
            $lines = [];

            foreach (is_array($draft['lines'] ?? null) ? $draft['lines'] : [] as $line) {
                $side = is_array($line) && is_string($line['side'] ?? null) ? Side::tryFrom($line['side']) : null;
                $code = is_array($line) && is_string($line['account_code'] ?? null) ? $line['account_code'] : '';
                $amount = is_array($line) && is_int($line['amount_yen'] ?? null) ? $line['amount_yen'] : 0;
                $accountId = $accountIdsByCode->get($code);

                if ($side === null || ! is_string($accountId) || $amount < 1) {
                    throw AiException::invalidOutput('仕訳の行が会社の勘定科目と合いません');
                }

                $lines[] = ['side' => $side, 'account_id' => $accountId, 'amount_yen' => $amount];
            }

            if (count($lines) < 2) {
                throw AiException::invalidOutput('仕訳の行が足りません');
            }

            [$entryDate, $late] = $this->resolveDate($lockedCompany, $reading['date'] ?? null);

            $vendor = is_string($draft['vendor'] ?? null) ? mb_substr($draft['vendor'], 0, 100) : null;
            $description = is_string($draft['description'] ?? null) ? mb_substr($draft['description'], 0, 200) : null;

            $entry = new JournalEntry;
            $entry->forceFill([
                'company_id' => $lockedCompany->id,
                'entry_date' => $entryDate,
                'kind' => EntryKind::Normal,
                'status' => EntryStatus::Draft,
                'description' => $description,
                'vendor' => $vendor,
                'category_id' => $category->id,
                'created_by' => null,
                'ai_run_id' => $run->id,
            ])->save();

            foreach ($lines as $index => $line) {
                $journalLine = new JournalLine;
                $journalLine->forceFill([
                    'company_id' => $lockedCompany->id,
                    'journal_entry_id' => $entry->id,
                    'line_no' => $index + 1,
                    'side' => $line['side'],
                    'account_id' => $line['account_id'],
                    'amount_yen' => $line['amount_yen'],
                ])->save();
            }

            $source->forceFill([
                'status' => SourceStatus::Drafted,
                'journal_entry_id' => $entry->id,
                'late' => $late,
            ])->save();

            AuditLog::record(
                user: null,
                action: 'entry.ai_draft',
                subject: $entry,
                after: ['source_record_id' => $source->id, 'ai_run_id' => $run->id],
                company: $lockedCompany,
            );

            return $entry;
        });
    }

    /**
     * 読めない・未来の日付は今日に、締めた期間の日付は締めた次の日にする。
     *
     * @return array{0: CarbonImmutable, 1: bool}
     */
    private function resolveDate(Company $company, mixed $rawDate): array
    {
        $today = CarbonImmutable::today();
        $date = $today;

        if (is_string($rawDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) === 1) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $rawDate);
            if ($parsed instanceof CarbonImmutable && $parsed->lessThanOrEqualTo($today)) {
                $date = $parsed;
            }
        }

        if ($company->closed_through !== null && $date->lessThanOrEqualTo($company->closed_through)) {
            return [CarbonImmutable::parse($company->closed_through)->addDay()->startOfDay(), true];
        }

        return [$date, false];
    }
}
