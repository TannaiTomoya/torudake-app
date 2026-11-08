<?php

namespace App\Services;

use App\Enums\ConcernFlag;
use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\SourceStatus;
use App\Models\AiCorrection;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\ReadingConfirmation;
use App\Models\SourceRecord;
use App\Models\User;
use App\Support\JournalBalanceChecker;
use App\Support\ReadingReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 経理の保存＝確定（03 §4.3）。直した欄は ai_corrections に前後・理由・間違いの型を残す。
 * エラーのキーは「直して確定」の画面のプロパティ名に合わせている。
 */
class ConfirmEntryService
{
    public function __construct(
        private readonly JournalLineWriter $lineWriter,
        private readonly ConcernRules $concernRules,
        private readonly JournalBalanceChecker $balanceChecker,
        private readonly WorkbookService $workbook,
    ) {}

    public function confirm(Company $company, User $user, string $sourceId, ConfirmEntryInput $input): JournalEntry
    {
        $entry = $this->confirmInTransaction($company, $user, $sourceId, $input);

        try {
            $this->workbook->upsertRow($company, $entry->id);
        } catch (Throwable $e) {
            // 確定は済んでいる。書けなかった行はダウンロードの前に syncMissing が足す。
            report($e);
        }

        return $entry->refresh();
    }

    private function confirmInTransaction(Company $company, User $user, string $sourceId, ConfirmEntryInput $input): JournalEntry
    {
        return DB::transaction(function () use ($company, $user, $sourceId, $input): JournalEntry {
            /** @var SourceRecord $source */
            $source = $company->sourceRecords()->lockForUpdate()->findOrFail($sourceId);

            if ($source->status === SourceStatus::Ignored) {
                throw ValidationException::withMessages(['amountYen' => '対象外にした原資料は確定できません。']);
            }

            $entry = $source->journal_entry_id !== null
                ? $company->journalEntries()->lockForUpdate()->findOrFail($source->journal_entry_id)
                : $this->newManualDraft($company, $user, $source);

            if ($entry->status === EntryStatus::Confirmed) {
                return $entry;
            }

            [$date, $category, $bankAccount, $amountYen] = $this->validated($company, $input);

            $before = [
                'date' => $entry->entry_date->toDateString(),
                'vendor' => $entry->vendor,
                'category' => $entry->category()->value('name'),
                'credit_account' => $entry->bankAccount()->value('name'),
                'amount' => $entry->amountYen(),
            ];
            $after = [
                'date' => $date->toDateString(),
                'vendor' => $input->vendor,
                'category' => $category->name,
                'credit_account' => $bankAccount->name,
                'amount' => $amountYen,
            ];

            // AI が読めず補った日付は、人が確かめるか直すまで保存しない（直す＝人が入れた値）
            $review = ReadingReview::items($entry->reading_review, $entry->ai_check);
            $dateConfirmed = in_array('date', $input->readingConfirmed, true);
            if (($review['date']['needs_confirmation'] ?? false) && $after['date'] === $before['date'] && ! $dateConfirmed) {
                throw ValidationException::withMessages(['confirmDate' => 'AIは日付を読めず、今日の日付を仮に入れています。写真を見て確かめ、「この日付でよい」にチェックするか、日付を直してください。']);
            }

            $wasPreviouslyConfirmed = $entry->confirmed_at !== null || $entry->status === EntryStatus::Returned;
            $tracksAiCorrections = $entry->ai_run_id !== null && ! $wasPreviouslyConfirmed;
            $changed = $this->changedFields($before, $after);

            if ($tracksAiCorrections && $changed !== [] && $input->reason === null) {
                throw ValidationException::withMessages(['reason' => 'AIの下書きを直したときは、直した理由を選んでください。']);
            }

            foreach ($tracksAiCorrections ? $changed : [] as $field) {
                $correction = new AiCorrection;
                $correction->forceFill([
                    'company_id' => $company->id,
                    'ai_run_id' => $entry->ai_run_id,
                    'journal_entry_id' => $entry->id,
                    'field' => $field,
                    'before' => $before[$field] === null ? null : (string) $before[$field],
                    'after' => $after[$field] === null ? null : (string) $after[$field],
                    'reason' => $input->reason,
                    'kernel' => mb_substr($this->kernel($field, $before, $after), 0, 200),
                    'vendor' => $input->vendor,
                    'corrected_by' => $user->id,
                ])->save();
            }

            $this->lineWriter->rewrite($entry, $category, $bankAccount, $amountYen);

            // 書き直した最新の行で検算する。合わなければ例外でこのトランザクションごと戻り、何も確定しない。
            $balance = $this->balanceChecker->forEntry($entry);
            if (! $balance->balanced()) {
                throw ValidationException::withMessages(['amountYen' => '貸借が合わないため確定できません（'.$balance->message().'）。']);
            }

            $amountMissing = ($tracksAiCorrections && $before['amount'] === null)
                || in_array(ConcernFlag::AmountMissing->value, $entry->concern_flags ?? [], true);
            $corrected = in_array(ConcernFlag::Corrected->value, $entry->concern_flags ?? [], true)
                || ($tracksAiCorrections && array_intersect($changed, ['category', 'amount']) !== []);

            $entry->forceFill([
                'entry_date' => $date,
                'vendor' => $input->vendor,
                'description' => $input->description,
                'category_id' => $category->id,
                'bank_account_id' => $bankAccount->id,
                'status' => EntryStatus::Confirmed,
                'created_by' => $entry->created_by ?? $user->id,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
                'returned_reason' => null,
                'concern_flags' => $this->concernRules->for($company, $amountYen, $entry->ai_check, $corrected, $amountMissing, $balance),
            ])->save();

            $entry->sources()->lockForUpdate()->get()->each(function (SourceRecord $linked) use ($date, $amountYen, $input): void {
                $linked->forceFill([
                    'status' => SourceStatus::Confirmed,
                    'occurred_on' => $date,
                    'amount_yen' => $amountYen,
                    'vendor' => $input->vendor,
                ])->save();
            });

            $confirmations = $this->recordReadingConfirmations($company, $user, $entry, $review, $before, $after, $dateConfirmed);

            AuditLog::record(
                user: $user,
                action: 'entry.confirm',
                subject: $entry,
                before: $before,
                after: [...$after, 'corrected' => $changed, 'reason' => $input->reason?->value, 'reading_review' => $confirmations],
                company: $company,
            );

            return $entry;
        });
    }

    /**
     * 要確認の理由があった欄ごとに、保存した人・日時・保存した値・読み取りの版（ai_run）を残す。
     * 記録の method：confirmed（確かめた）・changed（人が直した）・entered（AI が読めず人が入れた）・saved_as_is（注意が付いたまま保存）。
     * 値や読み取りの版が変われば新しい記録になり、前の記録は使い回さない。
     *
     * @param  array<string, array{field: string, label: string, origin: string, reasons: list<string>, needs_confirmation: bool}>  $review
     * @param  array<string, string|int|null>  $before
     * @param  array<string, string|int|null>  $after
     * @return array<string, string> 欄 => method
     */
    private function recordReadingConfirmations(Company $company, User $user, JournalEntry $entry, array $review, array $before, array $after, bool $dateConfirmed): array
    {
        $recorded = [];

        foreach ($review as $field => $item) {
            if ($item['reasons'] === []) {
                continue;
            }

            $value = $after[$field] ?? null;
            $method = match (true) {
                $item['origin'] !== 'read' && $before[$field] === null && $value !== null => 'entered',
                $before[$field] !== $value => 'changed',
                $field === 'date' && $dateConfirmed => 'confirmed',
                default => 'saved_as_is',
            };

            $row = new ReadingConfirmation;
            $row->forceFill([
                'company_id' => $company->id,
                'journal_entry_id' => $entry->id,
                'ai_run_id' => $entry->ai_run_id,
                'field' => $field,
                'method' => $method,
                'reasons' => $item['reasons'],
                'value' => $value === null ? null : mb_substr((string) $value, 0, 200),
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ])->save();

            $recorded[$field] = $method;
        }

        return $recorded;
    }

    /**
     * AI が失敗して下書きがない原資料は、人が最初から入れる。
     */
    private function newManualDraft(Company $company, User $user, SourceRecord $source): JournalEntry
    {
        $entry = new JournalEntry;
        $entry->forceFill([
            'company_id' => $company->id,
            'entry_date' => CarbonImmutable::today(),
            'kind' => EntryKind::Normal,
            'status' => EntryStatus::Draft,
            'created_by' => $user->id,
            'reading_review' => ReadingReview::forManual(),
        ])->save();

        $source->forceFill([
            'status' => SourceStatus::Drafted,
            'journal_entry_id' => $entry->id,
        ])->save();

        return $entry;
    }

    /**
     * @return array{0: CarbonImmutable, 1: Category, 2: BankAccount, 3: int}
     */
    private function validated(Company $company, ConfirmEntryInput $input): array
    {
        $errors = [];

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $input->entryDate) === 1 && CarbonImmutable::canBeCreatedFromFormat($input->entryDate, '!Y-m-d')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $input->entryDate)
            : null;
        if ($date === null) {
            $errors['entryDate'] = '日付を入れてください。';
        }

        /** @var Category|null $category */
        $category = $company->categories()->whereNull('archived_at')->whereKey($input->categoryId)->first();
        if ($category === null) {
            $errors['categoryId'] = '分類を選んでください。';
        }

        /** @var BankAccount|null $bankAccount */
        $bankAccount = $company->bankAccounts()->whereNull('archived_at')->whereKey($input->bankAccountId)->first();
        if ($bankAccount === null) {
            $errors['bankAccountId'] = '支払い元を選んでください。';
        }

        if ($input->amountYen === null || $input->amountYen < 1) {
            $errors['amountYen'] = '金額を入れてください。';
        }

        if ($date === null || $category === null || $bankAccount === null || $input->amountYen === null || $input->amountYen < 1) {
            throw ValidationException::withMessages($errors);
        }

        return [$date, $category, $bankAccount, $input->amountYen];
    }

    /**
     * AI の値があって、人が変えた欄。記載のなかった金額を入れたのは直しに数えない。
     *
     * @param  array<string, string|int|null>  $before
     * @param  array<string, string|int|null>  $after
     * @return list<string>
     */
    private function changedFields(array $before, array $after): array
    {
        $changed = [];

        foreach ($before as $field => $value) {
            if ($value !== null && $value !== $after[$field]) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, string|int|null>  $before
     * @param  array<string, string|int|null>  $after
     */
    private function kernel(string $field, array $before, array $after): string
    {
        $vendor = (string) ($after['vendor'] ?? '取引先不明');

        return match ($field) {
            'category' => "vendor:{$vendor} → category:{$after['category']}",
            'credit_account' => "vendor:{$vendor} → payment:{$after['credit_account']}",
            default => "{$field}:{$before[$field]} → {$after[$field]}",
        };
    }
}
