<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\Side;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\FiscalYear;
use App\Support\JournalBalanceChecker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 開始仕訳（期首の残高。#65）。前期の決算書から、期首の日付で会社に1件だけ入れる。
 * 入れた時点で確定で、AI・経理の保存・社長の戻しの流れには乗らない。直したときは監査に前後の行を残す。
 */
class OpeningEntryService
{
    public const MAX_LINES = 60;

    /** 開始仕訳は貸借対照表の残高なので、収益・費用の科目は入れない */
    public const BALANCE_SHEET_TYPES = [AccountType::Asset, AccountType::Liability, AccountType::Equity];

    public function __construct(private readonly JournalBalanceChecker $checker) {}

    /**
     * 使い始めた日（なければ今日）を含む期の初日。最初に入れたときに決まり、あとで使い始めた日を変えても動かさない。
     */
    public function openingDate(Company $company): CarbonImmutable
    {
        $base = $company->bookkeeping_start_date !== null ? CarbonImmutable::instance($company->bookkeeping_start_date) : CarbonImmutable::today();

        return FiscalYear::containing($base, $company->fiscal_year_end_month)->start;
    }

    /**
     * @param  list<array{account_id: string, side: string, amount: int}>  $lines
     */
    public function save(Company $company, User $user, array $lines): JournalEntry
    {
        return DB::transaction(function () use ($company, $user, $lines): JournalEntry {
            // 1社1件にするため、会社の行で直列にする
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);

            /** @var JournalEntry|null $existing */
            $existing = $lockedCompany->openingEntries()->lockForUpdate()->first();

            $rows = $this->validated($lockedCompany, $lines, $existing);

            if ($existing !== null) {
                $before = $this->auditValues($existing);
                if ($before['lines'] === $this->auditRows($rows)) {
                    return $existing;
                }

                $this->writeLines($existing, $rows);
                AuditLog::record(user: $user, action: 'opening_entry.update', subject: $existing, before: $before, after: $this->auditValues($existing), company: $lockedCompany);

                return $existing;
            }

            $entry = new JournalEntry;
            $entry->forceFill([
                'company_id' => $lockedCompany->id,
                'entry_date' => $this->openingDate($lockedCompany),
                'kind' => EntryKind::Opening,
                'status' => EntryStatus::Confirmed,
                'description' => '開始仕訳',
                'created_by' => $user->id,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ])->save();

            $this->writeLines($entry, $rows);
            AuditLog::record(user: $user, action: 'opening_entry.enter', subject: $entry, after: $this->auditValues($entry), company: $lockedCompany);

            return $entry;
        });
    }

    /**
     * @param  list<array{account_id: string, side: string, amount: int}>  $lines
     * @return list<array{account: Account, side: Side, amount: int}>
     */
    private function validated(Company $company, array $lines, ?JournalEntry $existing): array
    {
        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => '行は1〜'.self::MAX_LINES.'行で入れてください。']);
        }

        // 入れたあとで使わなくした科目でも、すでに開始仕訳にあれば直せるようにする
        $alreadyUsed = $existing?->lines()->pluck('account_id')->all() ?? [];

        $rows = [];
        foreach ($lines as $line) {
            /** @var Account $account */
            $account = $company->accounts()->findOrFail($line['account_id']);
            if ($account->archived_at !== null && ! in_array($account->id, $alreadyUsed, true)) {
                throw ValidationException::withMessages(['lines' => '使っていない科目は選べません。']);
            }
            if (! in_array($account->type, self::BALANCE_SHEET_TYPES, true)) {
                throw ValidationException::withMessages(['lines' => '開始仕訳に入れられるのは、資産・負債・純資産の科目だけです（「'.$account->name.'」は入れられません）。']);
            }
            $side = Side::tryFrom($line['side']);
            if ($side === null) {
                throw ValidationException::withMessages(['lines' => '借方か貸方を選んでください。']);
            }
            $rows[] = ['account' => $account, 'side' => $side, 'amount' => $line['amount']];
        }

        $result = $this->checker->check(array_map(fn (array $row): array => ['side' => $row['side'], 'amount' => $row['amount']], $rows));
        if (! $result->balanced()) {
            throw ValidationException::withMessages(['lines' => sprintf(
                '借方（%s円）と貸方（%s円）を同じにしてください。金額は1円以上です。',
                number_format($result->debitTotal),
                number_format($result->creditTotal),
            )]);
        }

        return $rows;
    }

    /**
     * @param  list<array{account: Account, side: Side, amount: int}>  $rows
     */
    private function writeLines(JournalEntry $entry, array $rows): void
    {
        $entry->lines()->get()->each(fn (JournalLine $line) => $line->delete());

        foreach ($rows as $index => $row) {
            $line = new JournalLine;
            $line->forceFill([
                'company_id' => $entry->company_id,
                'journal_entry_id' => $entry->id,
                'line_no' => $index + 1,
                'side' => $row['side'],
                'account_id' => $row['account']->id,
                'amount_yen' => $row['amount'],
            ])->save();
        }

        $entry->unsetRelation('lines');
    }

    /**
     * @return array{entry_date: string, lines: list<array{account_code: string, side: string, amount_yen: int}>}
     */
    private function auditValues(JournalEntry $entry): array
    {
        $lines = array_values($entry->lines()->with('account')->orderBy('line_no')->get()
            ->map(fn (JournalLine $line): array => ['account_code' => $line->account->code, 'side' => $line->side->value, 'amount_yen' => $line->amount_yen])
            ->values()->all());

        return ['entry_date' => $entry->entry_date->toDateString(), 'lines' => $lines];
    }

    /**
     * @param  list<array{account: Account, side: Side, amount: int}>  $rows
     * @return list<array{account_code: string, side: string, amount_yen: int}>
     */
    private function auditRows(array $rows): array
    {
        return array_map(fn (array $row): array => ['account_code' => $row['account']->code, 'side' => $row['side']->value, 'amount_yen' => $row['amount']], $rows);
    }
}
