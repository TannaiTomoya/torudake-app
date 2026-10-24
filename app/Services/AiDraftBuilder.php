<?php

namespace App\Services;

use App\Enums\BankAccountKind;
use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\SourceStatus;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Services\Ai\AiException;
use App\Support\ReadingChecks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * L1・L2 の出力から下書きの仕訳を作る。分類・口座は会社の中のものだけに対応させ、
 * 借方・貸方の行はコードが組む。金額の記載がない書類は、行のない下書きにする。
 */
class AiDraftBuilder
{
    public function __construct(
        private readonly JournalLineWriter $lineWriter,
        private readonly ConcernRules $concernRules,
        private readonly PaymentMemory $paymentMemory,
    ) {}

    /**
     * @param  array<string, mixed>  $reading  L1 の出力
     * @param  array<string, mixed>  $draft  L2 の出力
     */
    public function build(Company $company, string $sourceId, array $reading, array $draft, AiRun $run): ?JournalEntry
    {
        return DB::transaction(function () use ($company, $sourceId, $reading, $draft, $run): ?JournalEntry {
            /** @var SourceRecord $source */
            $source = $company->sourceRecords()->lockForUpdate()->findOrFail($sourceId);

            if ($source->status !== SourceStatus::New || $source->journal_entry_id !== null) {
                return null;
            }

            $category = $company->categories()
                ->whereNull('archived_at')
                ->where('name', is_string($draft['category'] ?? null) ? $draft['category'] : '')
                ->first();

            if ($category === null) {
                throw AiException::invalidOutput('分類が会社の一覧にありません');
            }

            $vendor = is_string($draft['vendor'] ?? null) ? $draft['vendor'] : (is_string($reading['vendor'] ?? null) ? $reading['vendor'] : null);
            $rememberedPayment = $this->paymentMemory->for($company, $vendor);
            $bankAccount = $rememberedPayment ?? $this->resolveBankAccount($company, $reading, $draft);

            if ($bankAccount === null) {
                throw AiException::invalidOutput('支払い元の口座がありません');
            }

            $total = $reading['total_yen'] ?? null;
            $amountYen = is_int($total) && $total >= 1 ? $total : null;

            $entry = new JournalEntry;
            $entry->forceFill([
                'company_id' => $company->id,
                'entry_date' => ReadingChecks::validReceiptDate($reading['date'] ?? null) ?? CarbonImmutable::today(),
                'kind' => EntryKind::Normal,
                'status' => EntryStatus::Draft,
                'description' => is_string($draft['description'] ?? null) ? mb_substr($draft['description'], 0, 200) : null,
                'vendor' => is_string($draft['vendor'] ?? null) ? mb_substr($draft['vendor'], 0, 100) : null,
                'category_id' => $category->id,
                'bank_account_id' => $bankAccount->id,
                'created_by' => null,
                'ai_run_id' => $run->id,
                'concern_flags' => $this->concernRules->for($company, $amountYen, null, false, $amountYen === null),
                'ai_check' => $rememberedPayment !== null ? ['remembered_payment' => $rememberedPayment->name] : null,
            ])->save();

            if ($amountYen !== null) {
                $this->lineWriter->rewrite($entry, $category, $bankAccount, $amountYen);
            }

            $source->forceFill([
                'status' => SourceStatus::Drafted,
                'journal_entry_id' => $entry->id,
            ])->save();

            AuditLog::record(
                user: null,
                action: 'entry.ai_draft',
                subject: $entry,
                after: ['source_record_id' => $source->id, 'ai_run_id' => $run->id, 'amount_yen' => $amountYen],
                company: $company,
            );

            return $entry;
        });
    }

    /**
     * 同じ店で人が直した支払い元があれば、それを先に使う（build）。なければ、後日の請求書なら「後日の請求」の口座を優先し、次に AI が選んだ名前、最後に会社の既定の口座。
     *
     * @param  array<string, mixed>  $reading
     * @param  array<string, mixed>  $draft
     */
    private function resolveBankAccount(Company $company, array $reading, array $draft): ?BankAccount
    {
        $active = $company->bankAccounts()->whereNull('archived_at')->orderBy('created_at')->get();

        if (($reading['payment_method'] ?? null) === 'invoice_later') {
            $invoice = $active->first(fn (BankAccount $b): bool => $b->kind === BankAccountKind::Invoice);
            if ($invoice !== null) {
                return $invoice;
            }
        }

        $named = is_string($draft['bank_account'] ?? null)
            ? $active->first(fn (BankAccount $b): bool => $b->name === $draft['bank_account'])
            : null;

        return $named
            ?? $active->first(fn (BankAccount $b): bool => $b->is_default_for_receipts)
            ?? $active->first();
    }
}
