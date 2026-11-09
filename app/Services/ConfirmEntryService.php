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
use App\Models\EntryPostEvent;
use App\Models\FirstConfirmation;
use App\Models\JournalEntry;
use App\Models\ReadingConfirmation;
use App\Models\SourceRecord;
use App\Models\User;
use App\Support\DuplicateCandidateMatcher;
use App\Support\DuplicateCandidates;
use App\Support\DuplicateTarget;
use App\Support\EntryRecommendation;
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
        private readonly DuplicateCandidateMatcher $duplicateMatcher,
        private readonly WorkbookService $workbook,
        private readonly EntryRecommender $recommender,
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

            $beforeDescription = $entry->description;
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

            // 保存の直前にも、最新の値と最新の候補で照合し直す（判断は値が同じときだけ使う）。止めはしない
            $duplicates = $this->duplicateMatcher->match($company, new DuplicateTarget(
                sourceId: $source->id,
                entryId: $entry->id,
                date: $date,
                vendor: $input->vendor,
                amountYen: $amountYen,
                direction: $category->direction,
                bankKind: $bankAccount->kind,
                dateUncertain: false,
            ));

            // 保存の直前に、履歴からの候補を最新の履歴で確かめ直し、経理がどう扱ったかを決める（#54）
            $recommendation = $wasPreviouslyConfirmed ? null : $this->recommendationOutcome($company, $entry, $input, $category, $bankAccount);

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
                'concern_flags' => $this->concernRules->for($company, $amountYen, $entry->ai_check, $corrected, $amountMissing, $balance, $duplicates->concerning() !== []),
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

            if ($wasPreviouslyConfirmed) {
                $this->recordPostEvent($company, $user, $entry, $before, $after, $beforeDescription, $input->description);
            } else {
                $this->recordFirstConfirmation($company, $user, $entry, $source, $before, $after, $beforeDescription, $input, $confirmations, $duplicates, $recommendation);
            }

            AuditLog::record(
                user: $user,
                action: 'entry.confirm',
                subject: $entry,
                before: $before,
                after: [...$after, 'corrected' => $changed, 'reason' => $input->reason?->value, 'reading_review' => $confirmations, 'duplicate_candidates' => array_map(fn ($c): array => ['entry_id' => $c->entryId, 'strength' => $c->strength->value, 'decision' => $c->review?->decision->value], array_slice($duplicates->all, 0, 10))],
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
     * 初回確定の時点の記録（#32）。1仕訳に1行で、あとから書き換えない。
     * 数えるのは「下書きに手を入れたか」。AI の答えが会計として正しかったかは、ここでは言わない。
     * 摘要は自由文のため、主な指標（手を入れなかった率）には含めず、別に記録する。
     *
     * @param  array<string, string|int|null>  $before
     * @param  array<string, string|int|null>  $after
     * @param  array<string, string>  $confirmations  欄 => 読み取りの確認の方法
     * @param  array<string, mixed>|null  $recommendation  履歴からの候補の扱い（recommendationOutcome）
     */
    private function recordFirstConfirmation(
        Company $company,
        User $user,
        JournalEntry $entry,
        SourceRecord $source,
        array $before,
        array $after,
        ?string $beforeDescription,
        ConfirmEntryInput $input,
        array $confirmations,
        DuplicateCandidates $duplicates,
        ?array $recommendation,
    ): void {
        if ($company->firstConfirmations()->where('journal_entry_id', $entry->id)->exists()) {
            return;
        }

        $isAi = $entry->ai_run_id !== null;
        $run = $isAi ? $company->aiRuns()->find($entry->ai_run_id) : null;
        $review = is_array($entry->reading_review) ? $entry->reading_review : [];
        $origin = fn (string $field): string => is_array($review[$field] ?? null) && is_string($review[$field]['origin'] ?? null)
            ? $review[$field]['origin']
            : 'unknown';

        $changed = $isAi ? $this->changedFields($before, $after) : [];
        $filled = [];
        foreach ($isAi ? $before : [] as $field => $value) {
            if ($value === null && $after[$field] !== null) {
                $filled[] = $field;
            }
        }

        $row = new FirstConfirmation;
        $row->forceFill([
            'company_id' => $company->id,
            'journal_entry_id' => $entry->id,
            'source_record_id' => $source->id,
            'kind' => $isAi ? FirstConfirmation::KIND_AI : FirstConfirmation::KIND_MANUAL,
            // 実際の AI（claude）で、デモ会社でないものだけを real にする。fake・replay・デモ会社は別集計
            'evaluation_class' => ! $company->is_demo && (! $isAi || $run?->driver === 'claude')
                ? FirstConfirmation::CLASS_REAL
                : FirstConfirmation::CLASS_DEMO,
            'ai_run_id' => $run?->id,
            'driver' => $run?->driver,
            'prompt_version' => $run?->prompt_version,
            'required_complete' => $isAi && $before['amount'] !== null && $origin('date') !== 'defaulted',
            'changed_fields' => $changed,
            'filled_fields' => $filled,
            'description_changed' => $isAi && $this->differs($beforeDescription, $input->description),
            'draft_origin' => [
                'date' => $origin('date'),
                'vendor' => $origin('vendor'),
                'amount' => $origin('amount'),
                'category' => $isAi ? 'ai' : 'none',
                'payment' => $isAi ? ($entry->draft_payment_origin ?? 'unknown') : 'none',
            ],
            'reading_methods' => $confirmations,
            'duplicate' => [
                'shown' => count($duplicates->all),
                'concerning' => count($duplicates->concerning()),
                'decided' => count(array_filter($duplicates->all, fn ($candidate): bool => $candidate->review !== null)),
            ],
            'recommendation' => $recommendation,
            'reason' => $changed !== [] ? $input->reason?->value : null,
            'first_confirmed_by' => $user->id,
            'first_confirmed_at' => $entry->confirmed_at ?? now(),
        ])->save();
    }

    /**
     * 履歴からの候補を、経理がどう扱ったか（初回確定の記録に残す。#54）。
     * - no_candidate：いま候補がない（失敗ではない）
     * - held：経理が「保留」にした
     * - adopted：分類・支払い元が、いまの候補と同じ
     * - rejected：別を選んだ（直した）
     * 別に、下書きを作ったときの候補の根拠が、その後変わって使えなくなったか（expired）も残す。
     * 採用しても確定にはならない。確定は経理の保存だけ。
     *
     * @return array<string, mixed>|null 記録する値。下書きに候補の記録がなく、いまも候補がなければ null
     */
    private function recommendationOutcome(Company $company, JournalEntry $entry, ConfirmEntryInput $input, Category $category, BankAccount $bankAccount): ?array
    {
        $snapshot = is_array($entry->recommendation) ? $entry->recommendation : null;
        $paymentMethod = is_string($snapshot['payment_method'] ?? null) ? $snapshot['payment_method'] : null;
        $current = $this->recommender->for($company, $input->vendor, $paymentMethod);
        $candidate = $current->top();
        $recorded = is_array($snapshot['candidates'] ?? null) && $snapshot['candidates'] !== [];

        if ($snapshot === null && $candidate === null) {
            return null;
        }

        // 下書きを作ったときの候補の根拠が、その後変わったか（戻された・直された・使えなくなった）。扱いの記録とは別に持つ
        $expired = $recorded && ! $this->recommender->stillValid($company, $snapshot ?? [], $entry->vendor);

        $outcome = match (true) {
            $candidate === null => 'no_candidate',
            $input->recommendationHeld => 'held',
            $candidate['category_id'] === $category->id && $candidate['bank_account_id'] === $bankAccount->id => 'adopted',
            default => 'rejected',
        };

        return [
            'version' => EntryRecommendation::VERSION,
            'status' => $current->status,
            'reasons' => $current->reasons,
            'usable_count' => $current->usableCount,
            'candidate_count' => $candidate['count'] ?? 0,
            'applied' => (bool) ($snapshot['applied'] ?? false),
            'expired' => $expired,
            'outcome' => $outcome,
        ];
    }

    /**
     * 戻したあとの再確定を、初回確定とは別に残す。どの欄が変わったか（摘要を含む）だけを持つ。
     *
     * @param  array<string, string|int|null>  $before
     * @param  array<string, string|int|null>  $after
     */
    private function recordPostEvent(Company $company, User $user, JournalEntry $entry, array $before, array $after, ?string $beforeDescription, ?string $afterDescription): void
    {
        $fields = [];
        foreach ($before as $field => $value) {
            if ($value !== $after[$field]) {
                $fields[] = $field;
            }
        }
        if ($this->differs($beforeDescription, $afterDescription)) {
            $fields[] = 'description';
        }

        $event = new EntryPostEvent;
        $event->forceFill([
            'company_id' => $company->id,
            'journal_entry_id' => $entry->id,
            'kind' => EntryPostEvent::KIND_RECONFIRMED,
            'changed_fields' => $fields,
            'actor_id' => $user->id,
            'occurred_at' => now(),
        ])->save();
    }

    private function differs(?string $a, ?string $b): bool
    {
        return trim((string) $a) !== trim((string) $b);
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
