<?php

use App\Enums\AiStatus;
use App\Enums\EntryStatus;
use App\Enums\SourceStatus;
use App\Models\Company;
use App\Models\SourceRecord;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('原資料')] class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    #[Url(as: 'status')]
    public string $status = '';

    public function mount(Company $company): void
    {
        $this->authorize('viewSources', $company);

        $this->company = $company;
        $this->companyId = $company->id;
    }

    /**
     * @return Collection<int, SourceRecord>
     */
    #[Computed]
    public function sources(): Collection
    {
        $this->authorize('viewSources', $this->company);

        $status = SourceStatus::tryFrom($this->status);

        return $this->company->sourceRecords()
            ->with(['submitter', 'evidence', 'journalEntry'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($this->status === 'returned', fn ($query) => $query->whereRelation('journalEntry', 'status', EntryStatus::Returned))
            ->latest('created_at')
            ->limit(100)
            ->get();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->company->sourceRecords()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n): int => (int) $n)
            ->all();

        $counts['returned'] = $this->company->sourceRecords()
            ->whereRelation('journalEntry', 'status', EntryStatus::Returned)
            ->count();

        return $counts;
    }

    public function selectStatus(string $status): void
    {
        $this->status = SourceStatus::tryFrom($status) !== null || $status === 'returned' ? $status : '';
    }
}; ?>

<section class="mx-auto max-w-4xl space-y-4 p-4 text-body text-ink"
         @if ($this->sources->contains(fn ($s) => in_array($s->ai_status, [AiStatus::Waiting, AiStatus::Reading], true))) wire:poll.3s @endif>
    @php
        $statusClasses = [
            'new' => 'bg-brand-soft text-brand-ink',
            'drafted' => 'bg-warn-soft text-warn',
            'confirmed' => 'bg-ok-soft text-ok',
            'ignored' => 'bg-panel text-ink-sub',
        ];
        $aiClasses = [
            'waiting' => 'text-ink-sub',
            'reading' => 'text-brand-ink',
            'done' => 'text-ok',
            'failed' => 'text-ng',
        ];
    @endphp

    <header class="flex items-baseline justify-between border-b border-line pb-3">
        <h1 class="text-2xl font-bold text-ink">原資料</h1>
        <p class="text-note text-ink-sub">{{ $company->name }}</p>
    </header>

    <nav class="flex flex-wrap gap-2" aria-label="状態で絞り込む">
        <button type="button" wire:click="selectStatus('')"
                @class(['rounded-full border-2 px-4 py-2 text-note font-bold', 'border-brand bg-brand-soft text-ink' => $status === '', 'border-line bg-paper text-ink-sub' => $status !== ''])>
            すべて {{ array_sum($this->counts) - ($this->counts['returned'] ?? 0) }}
        </button>
        @foreach (SourceStatus::cases() as $case)
            <button type="button" wire:click="selectStatus('{{ $case->value }}')"
                    @class(['rounded-full border-2 px-4 py-2 text-note font-bold', 'border-brand bg-brand-soft text-ink' => $status === $case->value, 'border-line bg-paper text-ink-sub' => $status !== $case->value])>
                {{ $case->label() }} {{ $this->counts[$case->value] ?? 0 }}
            </button>
            @if ($case === SourceStatus::Confirmed)
                <button type="button" wire:click="selectStatus('returned')"
                        @class(['rounded-full border-2 px-4 py-2 text-note font-bold', 'border-brand bg-brand-soft text-ink' => $status === 'returned', 'border-line bg-paper text-ink-sub' => $status !== 'returned'])>
                    {{ EntryStatus::Returned->label() }} {{ $this->counts['returned'] ?? 0 }}
                </button>
            @endif
        @endforeach
    </nav>

    @if ($this->sources->isEmpty())
        <p class="rounded-xl border border-line bg-panel p-6 text-center text-ink-sub">
            {{ $status === '' ? 'まだ届いた原資料はありません。' : '「'.(SourceStatus::tryFrom($status)?->label() ?? EntryStatus::Returned->label()).'」の原資料はありません。' }}
        </p>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-paper">
            @foreach ($this->sources as $source)
                <li class="flex items-center gap-4 p-3" wire:key="source-{{ $source->id }}" data-test="source-row">
                    <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-panel">
                        @if ($source->evidence?->isImage() && ! $source->evidence->isPurged())
                            <img src="{{ route('company.evidences.image', [$company, $source->evidence_id]) }}" alt="レシートの写真" class="size-16 object-cover" loading="lazy">
                        @else
                            <flux:icon.document-text class="size-8 text-muted" />
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-ink">{{ $source->vendor ?? '（店名はまだ読めていません）' }}</span>
                            @if ($source->amount_yen !== null)
                                <span class="font-bold text-ink">{{ number_format($source->amount_yen) }}円</span>
                            @endif
                            @if ($source->status === SourceStatus::Drafted && ($source->journalEntry?->ai_check['amount_missing'] ?? false) === true)
                                <span class="rounded bg-warn-soft px-2 text-note font-bold text-warn">金額を入れてください</span>
                            @endif
                        </div>
                        @php($daysAgo = (int) $source->created_at?->startOfDay()->diffInDays(now()->startOfDay()))
                        <p class="text-note text-ink-sub">
                            {{ $source->created_at?->isoFormat('M月D日(ddd)') }}に{{ $source->submitter?->name }}さんが送った
                            ・{{ $daysAgo === 0 ? '今日' : $daysAgo.'日前' }}
                            @if ($source->occurred_on)
                                ・取引日 {{ $source->occurred_on->isoFormat('M月D日(ddd)') }}
                            @endif
                        </p>
                        <p class="text-note {{ $aiClasses[$source->ai_status->value] }}">
                            AI：{{ $source->ai_status->label() }}@if ($source->ai_status === AiStatus::Reading && $source->ai_stage)（{{ $source->ai_stage }}）@endif
                            @if ($source->ai_status === AiStatus::Failed && $source->ai_error)
                                ・{{ $source->ai_error }}
                            @endif
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-2">
                        @if ($source->journalEntry?->status === EntryStatus::Returned)
                            <span class="rounded-full bg-ng-soft px-3 py-1 text-note font-bold text-ng">{{ EntryStatus::Returned->label() }}</span>
                        @else
                            <span class="rounded-full px-3 py-1 text-note font-bold {{ $statusClasses[$source->status->value] }}">{{ $source->status->label() }}</span>
                        @endif
                        @can('editDraft', $company)
                            @if ($source->status !== SourceStatus::Ignored && $source->ai_status !== AiStatus::Waiting && $source->ai_status !== AiStatus::Reading)
                                <a href="{{ route('company.sources.show', [$company, $source]) }}" wire:navigate class="text-note font-bold text-brand-ink underline">
                                    {{ $source->status === SourceStatus::Confirmed && $source->journalEntry?->status !== EntryStatus::Returned ? '見る' : '直して確定' }}
                                </a>
                            @endif
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
