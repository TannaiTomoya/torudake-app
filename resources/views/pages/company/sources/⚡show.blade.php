<?php

use App\Enums\AiStatus;
use App\Enums\BalanceProblem;
use App\Enums\ConcernFlag;
use App\Enums\CorrectionReason;
use App\Enums\DuplicateDecision;
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
use App\Services\DuplicateReviewService;
use App\Services\EntryRecommender;
use App\Services\IgnoreSourceService;
use App\Support\DuplicateCandidate;
use App\Support\DuplicateCandidateMatcher;
use App\Support\DuplicateCandidates;
use App\Support\EntryRecommendation;
use App\Support\JournalBalanceChecker;
use App\Support\ReadingChecks;
use App\Support\ReadingReview;
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

    public bool $confirmDate = false;

    /** 履歴からの候補を「保留」にして保存する（#54） */
    public bool $recommendationHeld = false;

    public string $duplicateReason = '';

    public string $duplicateMessage = '';

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
            : $this->company->journalEntries()->with(['lines.account', 'confirmer', 'readingConfirmations.confirmer'])->find($this->source->journal_entry_id);
    }

    /**
     * 二重計上の候補（コードの照合。保存されている値と最新の候補から、開くたびに引き直す）。
     */
    #[Computed]
    public function duplicates(): DuplicateCandidates
    {
        return app(DuplicateCandidateMatcher::class)->forSource($this->company, $this->source);
    }

    /**
     * 同じ店の確定履歴からの候補。AI の案ではなく、この会社の人が確定した履歴だけが根拠（開くたびに引き直す）。
     */
    #[Computed]
    public function recommendation(): EntryRecommendation
    {
        $snapshot = $this->entry?->recommendation;
        $paymentMethod = is_array($snapshot) && is_string($snapshot['payment_method'] ?? null) ? $snapshot['payment_method'] : null;

        return app(EntryRecommender::class)->for($this->company, trim($this->vendor) === '' ? null : trim($this->vendor), $paymentMethod);
    }

    /**
     * 下書きを作ったときに当てた候補の根拠が、その後変わって使えなくなったか。
     */
    #[Computed]
    public function recommendationExpired(): bool
    {
        $snapshot = $this->entry?->recommendation;

        return is_array($snapshot)
            && ($snapshot['applied'] ?? false) === true
            && ! app(EntryRecommender::class)->stillValid($this->company, $snapshot, $this->entry?->vendor);
    }

    /**
     * 候補を欄に入れる。確定にはならない（保存するのは経理）。確認する権限は「直して確定」と同じ。
     */
    public function useRecommendation(int $index): void
    {
        $this->authorize('editDraft', $this->company);


        $candidate = $this->recommendation->candidates[$index] ?? null;
        if ($candidate === null) {
            return;
        }

        $this->categoryId = $candidate['category_id'];
        $this->bankAccountId = $candidate['bank_account_id'];
        $this->recommendationHeld = false;
    }

    public function holdRecommendation(): void
    {
        $this->authorize('editDraft', $this->company);

        $this->recommendationHeld = true;
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
            readingConfirmed: $this->confirmDate ? ['date'] : [],
            recommendationHeld: $this->recommendationHeld,
        ));

        $this->goToNext('確定しました。');
    }

    public function decideDuplicate(string $candidateEntryId, string $decision, DuplicateReviewService $service): void
    {
        $this->authorize('editDraft', $this->company);
        $this->resetErrorBag();
        $this->duplicateMessage = '';

        $choice = DuplicateDecision::tryFrom($decision);

        if ($choice === null) {
            $this->addError('duplicateReason', '判断を選んでください。');

            return;
        }

        /** @var User $user */
        $user = Auth::user();

        $service->decide($this->company, $user, $this->sourceId, $candidateEntryId, $choice, $this->duplicateReason);

        $this->duplicateReason = '';

        if ($choice === DuplicateDecision::Duplicate) {
            $this->goToNext('重複として対象外にしました。');

            return;
        }

        unset($this->duplicates);
        $this->duplicateMessage = '判断を記録しました。';
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
    $balance = $entry !== null ? app(JournalBalanceChecker::class)->forEntry($entry) : null;
    $balanceUnchecked = $balance?->problem === BalanceProblem::Empty;
    $lowFields = ReadingChecks::lowConfidenceFields($check);
    $reviewItems = ReadingReview::items($entry?->reading_review, $check === [] ? null : $check);
    $reasonsFor = fn (string $field): array => array_values(array_diff($reviewItems[$field]['reasons'] ?? [], ['low_confidence']));
    $duplicates = $entry !== null ? $this->duplicates : null;
    $candidateSources = $duplicates !== null && $duplicates->all !== []
        ? $company->sourceRecords()->with('evidence')->whereIn('id', collect($duplicates->shown())->pluck('sourceId')->filter()->all())->get()->keyBy('id')
        : collect();
    $defaultedDate = ($reviewItems['date']['needs_confirmation'] ?? false) || ($entry === null);
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
            @if ($entry && $balance)
                <div class="space-y-2 rounded-xl border border-line bg-panel p-4" data-test="rule-check">
                    <h2 class="font-bold text-ink">規則の検算（保存されている仕訳の行）</h2>
                    @if ($balanceUnchecked)
                        <p class="text-warn" data-test="rule-unchecked">まだ検算していません。金額を入れて保存すると、借方と貸方の合計を確かめます。</p>
                    @else
                        <p @class(['font-bold', 'text-ok' => $balance->balanced(), 'text-ng' => ! $balance->balanced()]) data-test="rule-result">
                            借方 {{ number_format($balance->debitTotal) }}円 ／ 貸方 {{ number_format($balance->creditTotal) }}円
                            {{ $balance->balanced() ? '（一致）' : '（'.$balance->message().'）' }}
                        </p>
                        @if (! $balance->balanced())
                            <p class="text-note text-ink-sub">このままでは確定できません。</p>
                        @endif
                    @endif
                    <p class="text-note text-ink-sub">貸借が合っていても、科目や金額が正しいとは限りません。</p>
                    @if (! $balanceUnchecked && is_bool($check['balanced'] ?? null) && $check['balanced'] !== $balance->balanced())
                        <p class="text-warn" data-test="ai-balance-differs">AIの回答（貸借は{{ $check['balanced'] ? '一致' : '不一致' }}）は、保存されている行の検算と食い違っています。検算の結果を正とします。</p>
                    @endif
                </div>
            @endif
            @if ($reviewItems !== [])
                <div class="space-y-2 rounded-xl border border-line bg-panel p-4" data-test="reading-review">
                    <h2 class="font-bold text-ink">読み取りの確認（日付・店名・金額）</h2>
                    <ul class="space-y-1">
                        @foreach ($reviewItems as $item)
                            <li data-test="reading-item-{{ $item['field'] }}">
                                <span class="font-bold">{{ $item['label'] }}</span>：{{ ReadingReview::originLabel($item['origin']) }}
                                @foreach ($item['reasons'] as $code)
                                    <span class="block text-note text-warn">・{{ ReadingReview::reasonLabel($code) }}</span>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                    <p class="text-note text-ink-sub">AIの自信が高くても、読み違いはあります。写真と見比べてください。写真の上の位置は示せません。</p>
                    @if ($entry?->readingConfirmations->isNotEmpty())
                        <div class="space-y-1 border-t border-line pt-2" data-test="reading-confirmations">
                            <p class="font-bold">確認の記録</p>
                            @foreach ($entry->readingConfirmations as $confirmation)
                                <p class="text-note">
                                    {{ ReadingReview::FIELDS[$confirmation->field] ?? $confirmation->field }}「{{ $confirmation->value }}」：{{ match ($confirmation->method) {
                                        'confirmed' => '確かめた',
                                        'changed' => '人が直した',
                                        'entered' => '人が入れた',
                                        default => '注意が付いたまま保存した',
                                    } }}（{{ $confirmation->confirmer?->name }}・{{ $confirmation->confirmed_at->isoFormat('M月D日(ddd) H:mm') }}）
                                </p>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
            @if ($duplicates !== null && $entry?->amountYen() !== null)
                <div @class(['space-y-3 rounded-xl border bg-panel p-4', 'border-warn' => $duplicates->all !== [], 'border-line' => $duplicates->all === []]) data-test="{{ $duplicates->all !== [] ? 'duplicate-candidates' : 'duplicate-none' }}">
                    <h2 class="font-bold text-ink">二重計上の候補（金額・日付・店名の照合。前後{{ $duplicates->windowDays }}日・同じ金額）</h2>
                    @if ($duplicates->all === [])
                        <p class="text-note text-ink-sub">候補は見つかりませんでした。見つからないことは、重複がないことを保証しません（範囲外の日付・金額や店名がまったく違うものは探していません）。</p>
                    @else
                        <p class="text-note text-ink-sub">重複と決めつけるものではありません。同じ日・同じ店・同じ金額の別の取引もあります。写真と見比べて、人が判断してください。</p>
                        @if (! $readOnly)
                            <label class="block space-y-1">
                                <span class="font-bold">判断の理由（「別の取引」「対象外」のときは必ず）</span>
                                <input type="text" wire:model="duplicateReason" maxlength="200" data-test="duplicate-reason" class="w-full rounded-lg border border-line bg-paper px-3 py-2 text-body">
                            </label>
                            @error('duplicateReason') <p class="font-bold text-ng" data-test="duplicate-error">{{ $message }}</p> @enderror
                            @if ($duplicateMessage !== '') <p class="font-bold text-ok" data-test="duplicate-message">{{ $duplicateMessage }}</p> @endif
                        @endif
                        @foreach ($duplicates->shown() as $candidate)
                            @php
                                $candidateSource = $candidate->sourceId !== null ? $candidateSources->get($candidate->sourceId) : null;
                                $reviewed = $candidate->review;
                            @endphp
                            <div class="space-y-2 rounded-lg border border-line bg-paper p-3" data-test="duplicate-candidate" wire:key="dup-{{ $candidate->entryId }}">
                                <p class="font-bold">
                                    <span @class(['rounded-full px-3 py-1 text-note', 'bg-ng-soft text-ng' => $candidate->strength->value === 'strong', 'bg-warn-soft text-warn' => $candidate->strength->value !== 'strong'])>{{ $candidate->strength->label() }}</span>
                                    {{ \Illuminate\Support\Carbon::parse($candidate->date)->isoFormat('M月D日(ddd)') }}　{{ $candidate->vendor ?? '（店名なし）' }}　{{ number_format($candidate->amountYen) }}円　（{{ $candidate->statusLabel }}）
                                </p>
                                <ul class="text-note text-ink-sub">
                                    @foreach ($candidate->reasons as $code)
                                        <li>・{{ DuplicateCandidate::reasonLabel($code, $candidate->dayDifference) }}</li>
                                    @endforeach
                                </ul>
                                <div class="flex flex-wrap items-center gap-3">
                                    @if ($candidateSource?->evidence !== null && ! $candidateSource->evidence->isPurged() && $candidateSource->evidence->isImage())
                                        <img src="{{ route('company.evidences.image', [$company, $candidateSource->evidence_id]) }}" alt="候補の写真" class="h-28 rounded-lg border border-line object-contain">
                                    @elseif ($candidateSource !== null)
                                        <span class="text-note text-ink-sub">候補の写真は残っていません</span>
                                    @endif
                                    <a href="{{ $candidate->sourceId !== null ? route('company.sources.show', [$company, $candidate->sourceId]) : route('company.entries.show', [$company, $candidate->entryId]) }}" wire:navigate class="text-note font-bold text-brand-ink underline" data-test="duplicate-open">候補を開いて見比べる</a>
                                </div>
                                @if ($reviewed !== null)
                                    <p class="text-note font-bold" data-test="duplicate-reviewed">判断済み：{{ $reviewed->decision->label() }}（{{ $reviewed->decider?->name }}・{{ $reviewed->decided_at->isoFormat('M月D日(ddd) H:mm') }}）@if ($reviewed->reason)　理由：{{ $reviewed->reason }}@endif</p>
                                @endif
                                @if (! $readOnly && ($reviewed === null || $reviewed->decision === DuplicateDecision::Hold))
                                    <div class="flex flex-wrap gap-2">
                                        @foreach (DuplicateDecision::cases() as $choice)
                                            <button type="button" wire:click="decideDuplicate('{{ $candidate->entryId }}', '{{ $choice->value }}')" wire:loading.attr="disabled" data-test="duplicate-{{ $choice->value }}"
                                                    class="rounded-lg border-2 border-line bg-paper px-3 py-2 font-bold text-ink">{{ $choice->label() }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        @if ($duplicates->hiddenCount() > 0)
                            <p class="font-bold text-warn" data-test="duplicate-hidden">ほか {{ $duplicates->hiddenCount() }} 件の候補があります（表示は {{ $duplicates->displayLimit }} 件まで）。</p>
                        @endif
                    @endif
                </div>
            @endif
            @if ($entry && ($check !== []))
                <div class="space-y-2 rounded-xl border border-line bg-panel p-4" data-test="ai-check">
                    <h2 class="font-bold text-ink">AIの気になる点</h2>
                    @if (($check['amount_missing'] ?? false) === true)
                        <p class="text-warn">金額の記載がない書類です（後日の請求書で決まる金額）。検算は金額を入れてから行います。</p>
                    @endif
                    @if (($check['duplicate_suspected'] ?? false) === true)
                        <p class="text-warn" data-test="ai-duplicate-hint">AIの参考：重複の疑いがあるとAIが答えました。どの取引かはAIでは特定できません。上の「二重計上の候補」の照合で確かめてください。</p>
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
                        <p class="inline-block rounded bg-brand-soft px-2 py-1 font-bold text-brand-ink" data-test="remembered-payment">過去の確認（同じ店）から『{{ $check['remembered_payment'] }}』にしました</p>
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
                        @foreach (array_diff($reasonsFor('amount'), ['amount_missing']) as $code)
                            <span class="block text-note text-warn" data-test="reading-reason-amount">・{{ ReadingReview::reasonLabel($code) }}</span>
                        @endforeach
                        @error('amountYen') <span class="block text-ng">{{ $message }}</span> @enderror
                    </label>

                    <label class="block space-y-1">
                        <span class="font-bold">日付</span>
                        <input type="date" wire:model="entryDate" @class(['rounded-lg border px-3 py-2 text-body', 'border-warn bg-warn-soft' => $low('date'), 'border-line bg-paper' => ! $low('date')])>
                        @if ($low('date'))
                            <span class="block font-bold text-warn" data-test="check-date">確かめて（AIの読み取りの自信が低い）</span>
                        @endif
                        @foreach ($reasonsFor('date') as $code)
                            <span class="block text-note text-warn" data-test="reading-reason-date">・{{ ReadingReview::reasonLabel($code) }}</span>
                        @endforeach
                        @error('entryDate') <span class="block text-ng">{{ $message }}</span> @enderror
                    </label>
                    @if ($defaultedDate)
                        <label class="flex items-start gap-3 rounded-lg border border-warn bg-warn-soft p-3" data-test="confirm-date-box">
                            <input type="checkbox" wire:model="confirmDate" class="mt-1 size-5" data-test="confirm-date">
                            <span class="font-bold text-ink">AIは日付を読めず、今日の日付を仮に入れています。写真を見て、この日付でよいことを確かめた（違うときは日付を直してください）</span>
                        </label>
                        @error('confirmDate') <span class="block font-bold text-ng" data-test="confirm-date-error">{{ $message }}</span> @enderror
                    @endif

                    <label class="block space-y-1">
                        <span class="font-bold">取引先</span>
                        <input type="text" wire:model="vendor" maxlength="100" @class(['w-full rounded-lg border px-3 py-2 text-body', 'border-warn bg-warn-soft' => $low('vendor'), 'border-line bg-paper' => ! $low('vendor')])>
                        @if ($low('vendor'))
                            <span class="block font-bold text-warn" data-test="check-vendor">確かめて（AIの読み取りの自信が低い）</span>
                        @endif
                        @foreach ($reasonsFor('vendor') as $code)
                            <span class="block text-note text-warn" data-test="reading-reason-vendor">・{{ ReadingReview::reasonLabel($code) }}</span>
                        @endforeach
                    </label>

                    @php($recommendation = $this->recommendation)
                    @if ($recommendation->candidates !== [] || $this->recommendationExpired)
                        <div class="space-y-2 rounded-xl border border-line bg-panel p-4" data-test="recommendation" data-status="{{ $recommendation->status }}">
                            <p class="font-bold">過去の確認から<span class="ml-2 text-note font-normal text-ink-sub">（AIの案ではなく、この会社で確定した履歴から出しています）</span></p>
                            @if ($this->recommendationExpired)
                                <p class="text-warn" data-test="recommendation-expired">下書きに当てた候補の根拠が、その後変わりました。いまの履歴で確かめ直してください。</p>
                            @endif
                            @foreach ($recommendation->candidates as $index => $candidate)
                                <div class="flex flex-wrap items-center justify-between gap-2" data-test="recommendation-candidate">
                                    <p>分類「{{ $candidate['category_name'] }}」・支払い元「{{ $candidate['bank_account_name'] }}」<span class="block text-note text-ink-sub">同じ店で{{ $candidate['count'] }}件・最後の確認は{{ \Carbon\CarbonImmutable::parse($candidate['last_confirmed_on'])->isoFormat('M月D日(ddd)') }}</span></p>
                                    @if (! $recommendationHeld)
                                        <button type="button" wire:click="useRecommendation({{ $index }})" class="rounded-lg border-2 border-ink bg-paper px-3 py-1 font-bold text-ink" data-test="recommendation-use">今回も使う</button>
                                    @endif
                                </div>
                            @endforeach
                            @if ($recommendation->status === EntryRecommendation::HOLD)
                                <p class="text-note text-warn" data-test="recommendation-hold-reason">決めきれません：{{ implode('／', $recommendation->reasonLabels()) }}</p>
                            @endif
                            @if ($recommendationHeld)
                                <p class="text-note text-ink-sub" data-test="recommendation-held">候補は保留にしています（そのまま保存できます）</p>
                            @elseif ($recommendation->candidates !== [])
                                <button type="button" wire:click="holdRecommendation" class="text-note font-bold text-ink-sub underline" data-test="recommendation-hold">保留にする</button>
                            @endif
                            <p class="text-note text-ink-sub">別にするときは、下の欄を直してください。候補を使っても確定にはなりません。</p>
                        </div>
                    @endif

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
