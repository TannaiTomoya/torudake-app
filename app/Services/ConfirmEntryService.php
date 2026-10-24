<?php

namespace App\Services;

use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\SourceStatus;
use App\Models\AiCorrection;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Models\User;
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

            $changed = $entry->ai_run_id === null ? [] : $this->changedFields($before, $after);

            if ($changed !== [] && $input->reason === null) {
                throw ValidationException::withMessages(['reason' => 'AIの下書きを直したときは、直した理由を選んでください。']);
            }

            foreach ($changed as $field) {
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

            $amountMissing = $entry->ai_run_id !== null && $before['amount'] === null;
            $corrected = array_intersect($changed, ['category', 'amount']) !== [];

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
                'concern_flags' => $this->concernRules->for($company, $amountYen, $entry->ai_check, $corrected, $amountMissing),
            ])->save();

            $entry->sources()->lockForUpdate()->get()->each(function (SourceRecord $linked) use ($date, $amountYen, $input): void {
                $linked->forceFill([
                    'status' => SourceStatus::Confirmed,
                    'occurred_on' => $date,
                    'amount_yen' => $amountYen,
                    'vendor' => $input->vendor,
                ])->save();
            });

            AuditLog::record(
                user: $user,
                action: 'entry.confirm',
                subject: $entry,
                before: $before,
                after: [...$after, 'corrected' => $changed, 'reason' => $input->reason?->value],
                company: $company,
            );

            return $entry;
        });
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
            'category' => "vendor:{$vendor}→category:{$after['category']}",
            'credit_account' => "vendor:{$vendor}→payment:{$after['credit_account']}",
            default => "{$field}:{$before[$field]}→{$after[$field]}",
        };
    }
}
