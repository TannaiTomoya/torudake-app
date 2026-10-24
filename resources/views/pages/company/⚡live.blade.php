<?php

use App\Enums\AiStatus;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Enums\Side;
use App\Enums\SourceStatus;
use App\Models\AiRun;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\ConfirmEntryInput;
use App\Services\ConfirmEntryService;
use App\Support\RemainingCount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::live')] #[Title('ライブ')] class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    /** E で直しているあいだは、その1件に留める */
    #[Locked]
    public string $editingSourceId = '';

    public string $categoryId = '';

    public string $bankAccountId = '';

    public string $amountYen = '';

    public string $reason = '';

    #[Locked]
    public string $flash = '';

    public function mount(Company $company): void
    {
        $this->authorize('viewLive', $company);

        $this->company = $company;
        $this->companyId = $company->id;
    }

    /**
     * 今見せる1件：最新のまとめて送った回の、まだ確定していない一番古い写真。その回が済んだら、まだのものの一番新しい1件。
     */
    #[Computed]
    public function current(): ?SourceRecord
    {
        $with = ['evidence', 'journalEntry.lines.account', 'journalEntry.category', 'journalEntry.bankAccount'];

        if ($this->editingSourceId !== '') {
            $pinned = $this->company->sourceRecords()->with($with)->find($this->editingSourceId);
            if ($pinned !== null) {
                return $pinned;
            }
        }

        $open = fn (Builder $query) => $query->where(fn (Builder $q) => $q
            ->whereIn('status', [SourceStatus::New, SourceStatus::Drafted])
            ->orWhereRelation('journalEntry', 'status', EntryStatus::Returned));

        /** @var SourceRecord|null $latest */
        $latest = $this->company->sourceRecords()->with($with)
            ->where('status', '!=', SourceStatus::Ignored)
            ->latest('created_at')->latest('id')->first();

        if ($latest === null) {
            return null;
        }

        $batchQuery = $this->company->sourceRecords()->with($with)->where($open);
        $batchQuery = $latest->batch_id !== null
            ? $batchQuery->where('batch_id', $latest->batch_id)
            : $batchQuery->whereKey($latest->id);

        /** @var SourceRecord|null $inBatch */
        $inBatch = $batchQuery->oldest('created_at')->oldest('id')->first();
        if ($inBatch !== null) {
            return $inBatch;
        }

        /** @var SourceRecord|null $newestOpen */
        $newestOpen = $this->company->sourceRecords()->with($with)->where($open)->latest('created_at')->latest('id')->first();

        return $newestOpen ?? $latest;
    }

    /**
     * @return Collection<int, SourceRecord>
     */
    #[Computed]
    public function recent(): Collection
    {
        return $this->company->sourceRecords()->with('journalEntry')
            ->where('status', '!=', SourceStatus::Ignored)
            ->latest('created_at')->latest('id')->limit(3)->get();
    }

    #[Computed]
    public function remaining(): int
    {
        return RemainingCount::for($this->company);
    }

    #[Computed]
    public function confirmedThisMonth(): int
    {
        return $this->company->journalEntries()
            ->where('status', EntryStatus::Confirmed)
            ->whereBetween('entry_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->count();
    }

    #[Computed]
    public function aiSeconds(): ?float
    {
        $source = $this->current;
        if ($source === null || ! in_array($source->ai_status, [AiStatus::Done, AiStatus::Failed], true)) {
            return null;
        }

        $ms = AiRun::query()
            ->where('company_id', $this->company->id)
            ->whereIn('subject_id', array_filter([$source->id, $source->journal_entry_id]))
            ->sum('latency_ms');

        return round(((int) $ms) / 1000, 1);
    }

    #[Computed]
    public function aiReplayed(): bool
    {
        $source = $this->current;
        if ($source === null) {
            return false;
        }

        return AiRun::query()
            ->where('company_id', $this->company->id)
            ->whereIn('subject_id', array_filter([$source->id, $source->journal_entry_id]))
            ->where('driver', 'replay')
            ->exists();
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

    public function startEdit(string $sourceId): void
    {
        $this->authorize('editDraft', $this->company);
        $this->resetErrorBag();
        $this->flash = '';

        /** @var SourceRecord $source */
        $source = $this->company->sourceRecords()->with('journalEntry')->findOrFail($sourceId);
        $entry = $source->journalEntry;

        $this->editingSourceId = $source->id;
        $this->categoryId = (string) ($entry?->category_id ?? '');
        $this->bankAccountId = (string) ($entry?->bank_account_id
            ?? $this->company->bankAccounts()->whereNull('archived_at')->where('is_default_for_receipts', true)->value('id')
            ?? '');
        $amount = $entry?->amountYen() ?? $source->amount_yen;
        $this->amountYen = $amount === null ? '' : (string) $amount;
        $this->reason = '';
    }

    public function cancelEdit(): void
    {
        $this->resetErrorBag();
        $this->reset('editingSourceId', 'categoryId', 'bankAccountId', 'amountYen', 'reason');
    }

    public function save(string $sourceId, ConfirmEntryService $service): void
    {
        $this->authorize('editDraft', $this->company);
        $this->resetErrorBag();

        /** @var SourceRecord $source */
        $source = $this->company->sourceRecords()->with('journalEntry')->findOrFail($sourceId);
        $entry = $source->journalEntry;

        if ($this->editingSourceId === $source->id) {
            $digits = preg_replace('/[^0-9]/', '', mb_convert_kana($this->amountYen, 'n'));
            $input = new ConfirmEntryInput(
                entryDate: ($entry?->entry_date ?? $source->occurred_on ?? now())->toDateString(),
                vendor: $entry?->vendor ?? $source->vendor,
                description: $entry?->description,
                categoryId: $this->categoryId,
                bankAccountId: $this->bankAccountId,
                amountYen: $digits === '' || $digits === null ? null : (int) $digits,
                reason: CorrectionReason::tryFrom($this->reason),
            );
        } else {
            if ($entry === null) {
                $this->addError('amountYen', 'AIの下書きがまだありません。E で入れてください。');

                return;
            }

            $input = new ConfirmEntryInput(
                entryDate: $entry->entry_date->toDateString(),
                vendor: $entry->vendor,
                description: $entry->description,
                categoryId: (string) $entry->category_id,
                bankAccountId: (string) $entry->bank_account_id,
                amountYen: $entry->amountYen(),
            );
        }

        /** @var User $user */
        $user = Auth::user();

        $service->confirm($this->company, $user, $source->id, $input);

        $this->cancelEdit();
        $this->flash = '確定しました。Excel に1行入りました';
    }

    public function ownerUrl(): string
    {
        return Route::has('company.owner') ? route('company.owner', $this->company) : route('company.home', $this->company);
    }
}; ?>

@php
    $current = $this->current;
    $entry = $current?->journalEntry;
    $isEditing = $current !== null && $editingSourceId === $current->id;
    $isOpen = $current !== null && ($current->status === SourceStatus::New || $current->status === SourceStatus::Drafted || $entry?->status === EntryStatus::Returned);
    $isReading = $current !== null && in_array($current->ai_status, [AiStatus::Waiting, AiStatus::Reading], true);
    $amount = $entry?->amountYen() ?? $current?->amount_yen;
    $amountMissing = $current !== null && ! $isReading && $amount === null;
    $debit = $entry?->lines->first(fn (JournalLine $line) => $line->side === Side::Debit);
    $credit = $entry?->lines->first(fn (JournalLine $line) => $line->side === Side::Credit);
    $canSave = $isOpen && ! $isReading && $entry !== null && $amount !== null;
    $rows = [];
    if ($current !== null && ($entry !== null || $current->vendor !== null || $current->amount_yen !== null || $current->occurred_on !== null)) {
        $rows['店名'] = $entry?->vendor ?? $current?->vendor ?? '—';
        $rows['日付'] = ($entry?->entry_date ?? $current?->occurred_on)?->isoFormat('M月D日(ddd)') ?? '—';
        $rows['合計'] = $amount === null ? null : number_format($amount).'円';
    }
    if ($entry?->category !== null) {
        $rows['分類'] = $entry->category->name;
    }
    if ($entry?->bankAccount !== null) {
        $rows['支払い元'] = $entry->bankAccount->name;
    }
    $done = $this->remaining === 0 && $this->confirmedThisMonth > 0;
@endphp

<div wire:poll.500ms class="flex h-full flex-col gap-6 p-8 text-live text-ink"
     x-data
     x-on:keydown.window="
        if (['INPUT', 'SELECT', 'TEXTAREA'].includes($event.target.tagName)) { if ($event.key === 'Escape') { $wire.cancelEdit() } return }
        const card = document.querySelector('[data-current-source]');
        const id = card ? card.dataset.currentSource : null;
        if (($event.key === 'e' || $event.key === 'E') && id && card.dataset.open === '1') { $event.preventDefault(); $wire.startEdit(id) }
        else if (($event.key === 's' || $event.key === 'S') && id && card.dataset.canSave === '1') { $event.preventDefault(); $wire.save(id) }
        else if ($event.key === 'Escape' && card && card.dataset.editing === '1') { $wire.cancelEdit() }
        else if ($event.key === 'Escape') { window.location = '{{ route('company.home', $company) }}' }
        else if ($event.key === '3') { window.location = '{{ route('company.workbook', $company) }}' }
        else if ($event.key === '4') { window.location = '{{ route('company.ai', $company) }}' }
        else if ($event.key === '5') { window.location = '{{ $this->ownerUrl() }}' }
     ">
    <header class="relative flex items-center justify-between">
        <h1 class="font-bold"><a href="{{ route('company.home', $company) }}" class="text-ink hover:underline" data-test="live-home-link">撮って送るだけ帳簿</a></h1>
        <p class="font-bold text-ink-sub">{{ $company->name }}・{{ now()->month }}月</p>
        <p class="font-bold text-ng">● ライブ</p>

        @if ($flash !== '')
            <p wire:key="flash-{{ md5($flash.$this->confirmedThisMonth) }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 3000)" x-show="show" x-transition.opacity.duration.500ms
               class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded-xl border-2 border-ok bg-ok-soft px-6 font-bold text-ok" role="status" data-test="live-flash">{{ $flash }}</p>
        @endif
    </header>

    <div class="grid min-h-0 flex-1 grid-cols-5 gap-6">
        {{-- 最新の1件 --}}
        <section class="col-span-3 flex min-h-0 flex-col rounded-3xl border-4 {{ $isOpen ? 'border-brand' : 'border-line' }} bg-panel p-6">
            @if ($current === null)
                <div class="flex flex-1 items-center justify-center text-center font-bold text-ink-sub">
                    スマホで撮って送ると、ここに出ます
                </div>
            @else
                <div wire:key="card-{{ $current->id }}" class="live-slide-in flex min-h-0 flex-1 flex-col gap-4"
                     data-current-source="{{ $current->id }}" data-open="{{ $isOpen ? '1' : '0' }}" data-editing="{{ $isEditing ? '1' : '0' }}" data-can-save="{{ $canSave || $isEditing ? '1' : '0' }}" data-test="live-current">
                    <div class="flex min-h-0 flex-1 gap-6">
                        <div class="w-1/3 shrink-0 overflow-hidden rounded-2xl border border-line bg-paper">
                            @if ($current->evidence === null || $current->evidence->isPurged())
                                <div class="flex size-full items-center justify-center p-4 text-center font-bold text-ink-sub" data-test="live-photo-purged">写真は保存期間が過ぎたため削除しました</div>
                            @elseif (str_starts_with($current->evidence->mime, 'image/'))
                                <img src="{{ route('company.evidences.image', [$company, $current->evidence->id]) }}" alt="送られた写真" class="size-full object-contain">
                            @else
                                <div class="flex size-full items-center justify-center font-bold text-ink-sub">PDF</div>
                            @endif
                        </div>

                        <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-2 overflow-hidden">
                            @if ($isReading)
                                <div wire:key="reading-{{ $current->id }}" x-data="{ base: {{ (int) $current->created_at?->diffInMilliseconds(now()) }}, t0: Date.now(), now: Date.now() }" x-init="setInterval(() => now = Date.now(), 100)" data-test="reading">
                                    <p class="font-bold text-brand-ink">AIが読んでいます…（{{ $current->ai_stage ?? '受け取り' }}）<span x-text="((base + now - t0) / 1000).toFixed(1)"></span>秒</p>
                                    <p x-cloak x-show="base + now - t0 >= 15000 && base + now - t0 < 30000" class="font-bold text-warn">少し時間がかかっています</p>
                                    <p x-cloak x-show="base + now - t0 >= 30000" class="font-bold text-ng">時間がかかっています。E で手で入れられます</p>
                                </div>
                            @elseif ($current->ai_status === AiStatus::Failed)
                                <p class="font-bold text-ng" data-test="ai-failed">AIが読めませんでした。E で手で入れられます</p>
                            @else
                                <p class="font-bold text-brand-ink">AIが読み取りました{{ $this->aiSeconds !== null ? '（'.$this->aiSeconds.'秒）' : '' }}@if ($this->aiReplayed)<span class="ml-3 rounded bg-panel px-2 py-0.5 align-middle text-note font-bold text-ink-sub" data-test="live-replay">再生</span>@endif</p>
                            @endif

                            @if (! $isEditing)
                                <dl class="divide-y divide-line">
                                    @foreach ($rows as $label => $value)
                                        <div wire:key="row-{{ $current->id }}-{{ $label }}-{{ md5((string) $value) }}" class="live-reveal flex items-baseline justify-between gap-4 rounded-lg px-2 py-0.5" style="animation-delay: {{ $loop->index * 0.4 }}s">
                                            <dt class="shrink-0 whitespace-nowrap text-ink-sub">{{ $label }}</dt>
                                            @if ($value === null)
                                                <dd class="font-bold text-warn" data-test="live-amount-missing">金額を入れてください</dd>
                                            @else
                                                <dd class="min-w-0 truncate text-live-lg font-bold leading-tight">{{ $value }}</dd>
                                            @endif
                                        </div>
                                    @endforeach
                                </dl>

                            @else
                                {{-- E 直す：ライブ画面の上で直す --}}
                                <div class="flex flex-col gap-3" data-test="live-edit">
                                    <label class="flex items-center justify-between gap-4">
                                        <span class="text-ink-sub">分類</span>
                                        <select wire:model="categoryId" class="w-3/5 rounded-xl border-2 border-ink bg-paper px-3 py-2 font-bold">
                                            <option value="">選ぶ</option>
                                            @foreach ($this->categories as $category)
                                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    @error('categoryId') <p class="text-ng">{{ $message }}</p> @enderror
                                    <label class="flex items-center justify-between gap-4">
                                        <span class="text-ink-sub">合計</span>
                                        <span class="flex w-3/5 items-center gap-2">
                                            <input type="text" inputmode="numeric" wire:model="amountYen" class="w-full rounded-xl border-2 border-ink bg-paper px-3 py-2 text-right font-bold" placeholder="金額">
                                            <span>円</span>
                                        </span>
                                    </label>
                                    @error('amountYen') <p class="text-ng">{{ $message }}</p> @enderror
                                    <label class="flex items-center justify-between gap-4">
                                        <span class="text-ink-sub">支払い元</span>
                                        <select wire:model="bankAccountId" class="w-3/5 rounded-xl border-2 border-ink bg-paper px-3 py-2 font-bold">
                                            @foreach ($this->bankAccounts as $bankAccount)
                                                <option value="{{ $bankAccount->id }}">{{ $bankAccount->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-col gap-2">
                        @if (! $isEditing)
                                @if (is_string($entry?->ai_check['remembered_payment'] ?? null))
                                    <p class="live-reveal self-start rounded-lg bg-brand-soft px-3 py-1 font-bold text-brand-ink" data-test="live-remembered-payment">前回、この店は『{{ $entry->ai_check['remembered_payment'] }}』に直されました</p>
                                @elseif (($entry?->ai_check['remembered'] ?? false) === true)
                                    <p class="live-reveal self-start rounded-lg bg-brand-soft px-3 py-1 font-bold text-brand-ink" data-test="live-remembered">前回の修正を覚えています</p>
                                @endif
                                @if (is_string($entry?->ai_check['reason'] ?? null) && $entry->ai_check['reason'] !== '')
                                    <p class="truncate text-ink-sub">理由：{{ $entry->ai_check['reason'] }}</p>
                                @endif
                                @if ($debit !== null && $credit !== null)
                                    <p class="whitespace-nowrap rounded-xl border border-line bg-paper px-4 py-2 font-bold" data-test="live-journal">
                                        仕訳　{{ $debit->account?->name }} {{ number_format($debit->amount_yen) }}円 ／ {{ $credit->account?->name }} {{ number_format($credit->amount_yen) }}円
                                    </p>
                                @endif
                                @if (! $isOpen)
                                    <p class="self-start rounded-lg bg-ok-soft px-3 py-1 font-bold text-ok">{{ $entry?->status->label() ?? $current->status->label() }}</p>
                                @endif
                        @else
                                    @if ($entry?->ai_run_id !== null)
                                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="直した理由">
                                            @foreach (CorrectionReason::cases() as $case)
                                                <button type="button" wire:click="$set('reason', '{{ $case->value }}')"
                                                        class="rounded-xl border-2 px-3 py-1 font-bold {{ $reason === $case->value ? 'border-brand bg-brand-soft text-ink' : 'border-line bg-paper text-ink' }}">{{ $case->label() }}</button>
                                            @endforeach
                                        </div>
                                        @error('reason') <p class="text-ng">{{ $message }}</p> @enderror
                                    @endif
                        @endif
                    </div>

                    @if ($isOpen)
                        <div class="grid shrink-0 grid-cols-3 gap-4">
                            @if ($isEditing)
                                <button type="button" wire:click="cancelEdit" class="h-[76px] rounded-2xl border-2 border-ink bg-paper font-bold">やめる（Esc）</button>
                            @else
                                <button type="button" wire:click="startEdit('{{ $current->id }}')" class="h-[76px] rounded-2xl border-2 border-ink bg-paper font-bold" data-test="live-edit-button">E 直す</button>
                            @endif
                            @if ($canSave || $isEditing)
                                <button type="button" wire:click="save('{{ $current->id }}')" class="col-span-2 h-[76px] rounded-2xl border-4 border-brand bg-ink font-bold text-paper" data-test="live-save-button">S 保存（確定）して次へ</button>
                            @else
                                <p class="col-span-2 flex h-[76px] items-center justify-center rounded-2xl border-2 border-dashed border-ink bg-paper font-bold" data-test="live-save-disabled">
                                    {{ $amountMissing ? 'E で金額を入れると保存できます' : 'AIが読み終わると保存できます' }}
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

        </section>

        {{-- あと n 件・流れ --}}
        <aside class="col-span-2 flex min-h-0 flex-col gap-6">
            <div class="flex min-h-0 flex-1 flex-col items-center justify-center gap-4 rounded-3xl bg-ink p-6 text-center text-paper">
                <p class="font-bold">今月の帳簿ができるまで</p>
                <p wire:key="remaining-{{ $this->remaining }}" class="live-slide-in text-live-hero font-bold leading-none text-brand" data-test="live-remaining">あと {{ $this->remaining }}件</p>
            </div>
            <div class="shrink-0 overflow-hidden rounded-2xl bg-panel p-4">
                <p class="text-ink-sub">流れ</p>
                <ul class="divide-y divide-line" data-test="live-recent">
                    @foreach ($this->recent as $item)
                        @php
                            $itemEntry = $item->journalEntry;
                            $label = match (true) {
                                $itemEntry?->status === EntryStatus::Returned => ['社長から戻し', 'text-ng'],
                                $item->status === SourceStatus::Confirmed => ['確定', 'text-ok'],
                                $item->ai_status === AiStatus::Failed => ['手で入れる', 'text-ng'],
                                $item->status === SourceStatus::Drafted => ['下書き', 'text-brand-ink'],
                                default => ['読み取り中', 'text-ink-sub'],
                            };
                        @endphp
                        <li wire:key="recent-{{ $item->id }}" class="live-slide-in flex items-baseline justify-between gap-3 py-2 {{ $current?->id === $item->id ? 'font-bold' : '' }}">
                            <span class="truncate">{{ $item->created_at?->format('H:i') }} {{ $itemEntry?->vendor ?? $item->vendor ?? '—' }} {{ ($itemEntry?->amountYen() ?? $item->amount_yen) !== null ? number_format($itemEntry?->amountYen() ?? $item->amount_yen).'円' : '' }}</span>
                            <span class="shrink-0 font-bold {{ $label[1] }}">{{ $label[0] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </aside>
    </div>

    <footer class="flex items-center justify-between font-bold text-ink-sub">
        <p>確定は人が保存します。写真は30日で消え、AIの学習には使われません。</p>
        <p data-test="live-keys">3 Excel　4 AIの記録　<a href="{{ $this->ownerUrl() }}" class="text-ink underline" data-test="live-owner-link">5 社長の画面</a>　Esc ホーム</p>
    </footer>

    @if ($done)
        <div class="live-slide-in fixed inset-0 flex flex-col items-center justify-center gap-8 bg-paper text-center" data-test="live-done">
            <p class="text-live-hero font-bold leading-tight text-ink">今月の帳簿が<br>できました</p>
            <p class="font-bold text-ink-sub">3 で Excel を開く　5 で社長の画面</p>
        </div>
    @endif
</div>
