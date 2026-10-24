<?php

use App\Enums\AiStatus;
use App\Enums\ConcernFlag;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Enums\SourceStatus;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\ConfirmEntryInput;
use App\Services\ConfirmEntryService;
use App\Services\IgnoreSourceService;
use App\Support\ReadingChecks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('直して確定')] class extends Component {
    #[Locked]
    public string $companyId = '';

    #[Locked]
    public string $sourceId = '';

    public Company $company;

    public string $entryDate = '';

    public string $vendor = '';

    public string $description = '';

    public string $categoryId = '';

    public string $bankAccountId = '';

    public string $amountYen = '';

    public string $reason = '';

    public string $ignoreReason = '';

    public bool $showIgnore = false;

    public function mount(Company $company, string $source): void
    {
        $this->authorize('editDraft', $company);

        $this->company = $company;
        $this->companyId = $company->id;

        /** @var SourceRecord $record */
        $record = $company->sourceRecords()->findOrFail($source);
        $this->sourceId = $record->id;

        $entry = $record->journalEntry;

        $this->entryDate = ($entry?->entry_date ?? $record->occurred_on ?? now())->toDateString();
        $this->vendor = (string) ($entry?->vendor ?? $record->vendor ?? '');
        $this->description = (string) ($entry?->description ?? '');
        $this->categoryId = (string) ($entry?->category_id ?? '');
        $this->bankAccountId = (string) ($entry?->bank_account_id
            ?? $company->bankAccounts()->whereNull('archived_at')->where('is_default_for_receipts', true)->value('id')
            ?? '');
        $amount = $entry?->amountYen() ?? $record->amount_yen;
        $this->amountYen = $amount === null ? '' : (string) $amount;
    }

    #[Computed]
    public function source(): SourceRecord
    {
        /** @var SourceRecord $source */
        $source = $this->company->sourceRecords()->with(['evidence', 'submitter'])->findOrFail($this->sourceId);

        return $source;
    }

    #[Computed]
    public function entry(): ?JournalEntry
    {
        return $this->source->journal_entry_id === null
            ? null
            : $this->company->journalEntries()->with(['lines.account', 'confirmer'])->find($this->source->journal_entry_id);
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->company->categories()->whereNull('archived_at')->orderBy('sort_order')->get();
    }

    /**
     * @return Collection<int, BankAccount>
     */
    #[Computed]
    public function bankAccounts(): Collection
    {
        return $this->company->bankAccounts()->whereNull('archived_at')->orderBy('created_at')->get();
    }

    public function confirm(ConfirmEntryService $service): void
    {
        $this->authorize('editDraft', $this->company);
        $this->resetErrorBag();

        $digits = preg_replace('/[^0-9]/', '', mb_convert_kana($this->amountYen, 'n'));

        /** @var User $user */
        $user = Auth::user();

        $service->confirm($this->company, $user, $this->sourceId, new ConfirmEntryInput(
            entryDate: $this->entryDate,
            vendor: trim($this->vendor) === '' ? null : mb_substr(trim($this->vendor), 0, 100),
            description: trim($this->description) === '' ? null : mb_substr(trim($this->description), 0, 200),
            categoryId: $this->categoryId,
            bankAccountId: $this->bankAccountId,
            amountYen: $digits === '' || $digits === null ? null : (int) $digits,
            reason: CorrectionReason::tryFrom($this->reason),
        ));

        $this->goToNext('確定しました。');
    }

    public function ignore(IgnoreSourceService $service): void
    {
        $this->authorize('editDraft', $this->company);
        $this->resetErrorBag();

        /** @var User $user */
        $user = Auth::user();

        $service->ignore($this->company, $user, $this->sourceId, $this->ignoreReason);

        $this->goToNext('対象外にしました。');
    }

    private function goToNext(string $message): void
    {
        $next = $this->company->sourceRecords()
            ->whereKeyNot($this->sourceId)
            ->where(fn ($query) => $query
                ->where('status', SourceStatus::Drafted)
                ->orWhereRelation('journalEntry', 'status', EntryStatus::Returned)
                ->orWhere(fn ($q) => $q->where('status', SourceStatus::New)->where('ai_status', AiStatus::Failed)))
            ->oldest('created_at')
            ->first();

        session()->flash('status', $message);

        $this->redirectRoute(
            $next !== null ? 'company.sources.show' : 'company.sources.index',
            $next !== null ? [$this->company, $next] : [$this->company],
            navigate: true,
        );
    }
}; ?>

@php
    $source = $this->source;
    $entry = $this->entry;
    $check = $entry?->ai_check ?? [];
    $lowFields = ReadingChecks::lowConfidenceFields($check);
    $low = fn (string $key): bool => in_array($key, $lowFields, true);
    $addresseeFlag = match ($check['addressee_kind'] ?? null) {
        'other_company' => ConcernFlag::AddresseeOtherCompany,
        'person' => ConcernFlag::AddresseePerson,
        default => null,
    };
    $amountMissing = ($check['amount_missing'] ?? false) === true && $this->amountYen === '';
    $readOnly = $entry?->status === EntryStatus::Confirmed || $source->status === SourceStatus::Ignored;
    $flags = collect($entry?->concern_flags ?? [])->map(fn ($f) => ConcernFlag::tryFrom($f))->filter();
@endphp

<section class="mx-auto max-w-5xl space-y-6 p-4 text-body text-ink">
    <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-line pb-3">
        <div>
            <h1 class="text-2xl font-bold text-ink">{{ $readOnly ? '確定した仕訳' : '直して確定' }}</h1>
            <p class="text-note text-ink-sub">
                {{ $source->created_at?->isoFormat('M月D日(ddd)') }}に{{ $source->submitter?->name }}さんが送った
                @if ($source->note) ・メモ「{{ $source->note }}」@endif
            </p>
        </div>
        <a href="{{ route('company.sources.index', $company) }}" wire:navigate class="text-note font-bold text-brand-ink underline">原資料の一覧へ</a>
    </header>

    @if (session('status'))
        <p class="rounded-xl bg-ok-soft p-3 font-bold text-ok">{{ session('status') }}</p>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="space-y-3">
            <div class="overflow-hidden rounded-xl border border-line bg-panel">
                @if ($source->evidence === null || $source->evidence->isPurged())
                    <p class="p-6 text-center font-bold text-ink-sub" data-test="source-photo-purged">写真は保存期間が過ぎたため削除しました</p>
                @elseif ($source->evidence->isImage())
                    <img src="{{ route('company.evidences.image', [$company, $source->evidence_id]) }}" alt="送られた写真" class="w-full object-contain">
                @else
                    <a href="{{ route('company.evidences.image', [$company, $source->evidence_id]) }}" target="_blank" class="block p-6 text-center font-bold text-brand-ink underline">{{ $source->evidence->mime === 'application/pdf' ? 'PDF を開く' : '写真を開く' }}</a>
                @endif
            </div>
            @if ($source->ai_status === AiStatus::Failed)
                <p class="rounded-xl bg-ng-soft p-3 text-ng">AIが読めませんでした（{{ $source->ai_error }}）。写真を見て、手で入れてください。</p>
            @endif
        </div>

        <div class="space-y-4">
            @if ($entry && ($check !== []))
                <div class="space-y-2 rounded-xl border border-line bg-panel p-4" data-test="ai-check">
                    <h2 class="font-bold text-ink">AIの検算</h2>
                    @if (($check['amount_missing'] ?? false) === true)
                        <p class="text-warn">金額の記載がない書類です（後日の請求書で決まる金額）。検算は金額を入れてから行います。</p>
                    @else
                        <p @class(['font-bold', 'text-ok' => ($check['balanced'] ?? false) === true, 'text-ng' => ($check['balanced'] ?? false) !== true])>
                            借方 {{ number_format((int) ($check['debit_total'] ?? 0)) }}円 ／ 貸方 {{ number_format((int) ($check['credit_total'] ?? 0)) }}円
                            {{ ($check['balanced'] ?? false) === true ? '（一致）' : '（合っていません）' }}
                        </p>
                    @endif
                    @foreach (($check['concerns'] ?? []) as $concern)
                        <p class="text-warn">・{{ $concern }}</p>
                    @endforeach
                    @if (is_string($check['addressee_kind'] ?? null))
                        <p @class(['font-bold text-ng' => $addresseeFlag !== null]) data-test="addressee">
                            宛名：{{ $check['addressee'] ?? '（書かれていない）' }}@if ($addresseeFlag !== null)<span data-test="addressee-flag">　{{ $addresseeFlag->label() }}</span>@endif
                        </p>
                    @endif
                    @if (is_string($check['remembered_payment'] ?? null))
                        <p class="inline-block rounded bg-brand-soft px-2 py-1 font-bold text-brand-ink" data-test="remembered-payment">前回、この店は『{{ $check['remembered_payment'] }}』に直されました</p>
                    @elseif (($check['remembered'] ?? false) === true)
                        <p class="inline-block rounded bg-brand-soft px-2 py-1 font-bold text-brand-ink" data-test="remembered">前回の修正を覚えています</p>
                    @endif
                    @if (is_string($check['reason'] ?? null))
                        <p class="text-note text-ink-sub">AIの理由：{{ $check['reason'] }}</p>
                    @endif
                </div>
            @endif

            @if ($flags->isNotEmpty())
                <div class="flex flex-wrap gap-2" data-test="concern-flags">
                    @foreach ($flags as $flag)
                        <span class="rounded-full bg-warn-soft px-3 py-1 text-note font-bold text-warn">{{ $flag->label() }}</span>
                    @endforeach
                </div>
            @endif

            @if ($readOnly)
                <dl class="grid grid-cols-3 gap-2 rounded-xl border border-line bg-paper p-4">
                    <dt class="text-ink-sub">状態</dt><dd class="col-span-2 font-bold">{{ $source->status === SourceStatus::Ignored ? '対象外（'.$source->ignored_reason.'）' : $entry?->status->label() }}</dd>
                    @if ($entry)
                        <dt class="text-ink-sub">日付</dt><dd class="col-span-2">{{ $entry->entry_date->isoFormat('M月D日(ddd)') }}</dd>
                        <dt class="text-ink-sub">取引先</dt><dd class="col-span-2">{{ $entry->vendor }}</dd>
                        @foreach ($entry->lines as $line)
                            <dt class="text-ink-sub">{{ $line->side->label() }}</dt><dd class="col-span-2">{{ $line->account?->name }} {{ number_format($line->amount_yen) }}円</dd>
                        @endforeach
                        <dt class="text-ink-sub">確定</dt><dd class="col-span-2">{{ $entry->confirmer?->name }}・{{ $entry->confirmed_at?->isoFormat('M月D日(ddd) H:mm') }}</dd>
                    @endif
                </dl>
            @else
                <form wire:submit="confirm" class="space-y-4 rounded-xl border border-line bg-paper p-4">
                    @if ($entry?->status === EntryStatus::Returned)
                        <p class="rounded-lg bg-ng-soft p-3 text-ng">社長から戻されました：{{ $entry->returned_reason }}</p>
                    @endif

                    <label class="block space-y-1">
                        <span class="font-bold">金額</span>
                        <div class="flex items-center gap-2">
                            <input type="text" inputmode="numeric" wire:model.live.blur="amountYen" data-test="amount"
                                   @class(['w-48 rounded-lg border px-3 py-2 text-right text-body', 'border-warn bg-warn-soft' => $amountMissing || $low('total'), 'border-line bg-paper' => ! ($amountMissing || $low('total'))])
                                   placeholder="例 5200">
                            <span>円</span>
                        </div>
                        @if ($amountMissing)
                            <span class="block font-bold text-warn" data-test="amount-missing">金額を入れてください（請求書が届いたら、その金額）</span>
                        @elseif ($low('total'))
                            <span class="block font-bold text-warn" data-test="check-total">確かめて（AIの読み取りの自信が低い）</span>
                        @endif
                        @error('amountYen') <span class="block text-ng">{{ $message }}</span> @enderror
                    </label>

                    <label class="block space-y-1">
                        <span class="font-bold">日付</span>
                        <input type="date" wire:model="entryDate" @class(['rounded-lg border px-3 py-2 text-body', 'border-warn bg-warn-soft' => $low('date'), 'border-line bg-paper' => ! $low('date')])>
                        @if ($low('date'))
                            <span class="block font-bold text-warn" data-test="check-date">確かめて（AIの読み取りの自信が低い）</span>
                        @endif
                        @error('entryDate') <span class="block text-ng">{{ $message }}</span> @enderror
                    </label>

                    <label class="block space-y-1">
                        <span class="font-bold">取引先</span>
                        <input type="text" wire:model="vendor" maxlength="100" @class(['w-full rounded-lg border px-3 py-2 text-body', 'border-warn bg-warn-soft' => $low('vendor'), 'border-line bg-paper' => ! $low('vendor')])>
                        @if ($low('vendor'))
                            <span class="block font-bold text-warn" data-test="check-vendor">確かめて（AIの読み取りの自信が低い）</span>
                        @endif
                    </label>

                    <label class="block space-y-1">
                        <span class="font-bold">分類</span>
                        <select wire:model="categoryId" class="w-full rounded-lg border border-line bg-paper px-3 py-2 text-body">
                            <option value="">選んでください</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}（{{ $category->account?->name }}）</option>
                            @endforeach
                        </select>
                        @error('categoryId') <span class="block text-ng">{{ $message }}</span> @enderror
                    </label>

                    <label class="block space-y-1">
                        <span class="font-bold">支払い元</span>
                        <select wire:model="bankAccountId" class="w-full rounded-lg border border-line bg-paper px-3 py-2 text-body">
                            <option value="">選んでください</option>
                            @foreach ($this->bankAccounts as $bankAccount)
                                <option value="{{ $bankAccount->id }}">{{ $bankAccount->name }}</option>
                            @endforeach
                        </select>
                        @error('bankAccountId') <span class="block text-ng">{{ $message }}</span> @enderror
                    </label>

                    <label class="block space-y-1">
                        <span class="font-bold">摘要</span>
                        <input type="text" wire:model="description" maxlength="200" class="w-full rounded-lg border border-line bg-paper px-3 py-2 text-body">
                    </label>

                    @if ($entry?->ai_run_id && $entry->confirmed_at === null && $entry->status !== EntryStatus::Returned)
                        <label class="block space-y-1" data-test="correction-reason">
                            <span class="font-bold">直した理由</span>
                            <span class="block text-note text-ink-sub">AIの下書きを直したときだけ選ぶ（AIが次から覚えます）</span>
                            <select wire:model="reason" class="w-full rounded-lg border border-line bg-paper px-3 py-2 text-body">
                                <option value="">直していない</option>
                                @foreach (CorrectionReason::cases() as $case)
                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                @endforeach
                            </select>
                            @error('reason') <span class="block text-ng">{{ $message }}</span> @enderror
                        </label>
                    @endif

                    <button type="submit" wire:loading.attr="disabled" data-test="confirm"
                            class="w-full rounded-xl bg-brand px-4 py-4 text-lg font-bold text-ink disabled:opacity-60">
                        保存（確定）して次へ
                    </button>
                </form>

                <div class="space-y-2">
                    @if (! $showIgnore)
                        <button type="button" wire:click="$set('showIgnore', true)" class="text-note font-bold text-ink-sub underline">対象外にする（私的な買い物・二重に送った など）</button>
                    @else
                        <form wire:submit="ignore" class="space-y-2 rounded-xl border border-line bg-panel p-4">
                            <label class="block space-y-1">
                                <span class="font-bold">対象外にする理由</span>
                                <input type="text" wire:model="ignoreReason" maxlength="200" class="w-full rounded-lg border border-line bg-paper px-3 py-2 text-body">
                            </label>
                            @error('ignoreReason') <span class="block text-ng">{{ $message }}</span> @enderror
                            <button type="submit" class="rounded-lg bg-ink px-4 py-2 font-bold text-paper">対象外にする</button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>
</section>
