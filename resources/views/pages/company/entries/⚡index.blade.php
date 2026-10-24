<?php

use App\Enums\EntryStatus;
use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('仕訳の一覧')] class extends Component {
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
     * @return Collection<int, JournalEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        $this->authorize('viewSources', $this->company);

        $status = EntryStatus::tryFrom($this->status);

        return $this->company->journalEntries()
            ->with(['lines.account', 'category', 'confirmer'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('entry_date')
            ->latest('created_at')
            ->limit(100)
            ->get();
    }
}; ?>

<section class="mx-auto max-w-4xl space-y-4 p-4 text-body text-ink">
    <header class="flex items-baseline justify-between border-b border-line pb-3">
        <h1 class="text-2xl font-bold text-ink">仕訳の一覧</h1>
        <p class="text-note text-ink-sub">{{ $company->name }}</p>
    </header>

    <nav class="flex flex-wrap gap-2" aria-label="状態で絞り込む">
        <button type="button" wire:click="$set('status', '')"
                @class(['rounded-full border-2 px-4 py-2 text-note font-bold', 'border-brand bg-brand-soft text-ink' => $status === '', 'border-line bg-paper text-ink-sub' => $status !== ''])>すべて</button>
        @foreach (EntryStatus::cases() as $case)
            <button type="button" wire:click="$set('status', '{{ $case->value }}')"
                    @class(['rounded-full border-2 px-4 py-2 text-note font-bold', 'border-brand bg-brand-soft text-ink' => $status === $case->value, 'border-line bg-paper text-ink-sub' => $status !== $case->value])>{{ $case->label() }}</button>
        @endforeach
    </nav>

    @if ($this->entries->isEmpty())
        <p class="rounded-xl border border-line bg-panel p-6 text-center text-ink-sub">仕訳はまだありません。</p>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-paper">
            @foreach ($this->entries as $entry)
                @php($amount = $entry->amountYen())
                <li wire:key="entry-{{ $entry->id }}" data-test="entry-row">
                    <a href="{{ route('company.entries.show', [$company, $entry]) }}" wire:navigate class="flex items-center gap-4 p-3 hover:bg-panel">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold">{{ $entry->vendor ?? '取引先なし' }}</span>
                                <span class="font-bold">{{ $amount === null ? '金額なし' : number_format($amount).'円' }}</span>
                            </div>
                            <p class="text-note text-ink-sub">{{ $entry->entry_date->isoFormat('M月D日(ddd)') }}・{{ $entry->category?->name }}</p>
                        </div>
                        <span @class([
                            'shrink-0 rounded-full px-3 py-1 text-note font-bold',
                            'bg-warn-soft text-warn' => $entry->status === EntryStatus::Draft,
                            'bg-ok-soft text-ok' => $entry->status === EntryStatus::Confirmed,
                            'bg-ng-soft text-ng' => $entry->status === EntryStatus::Returned,
                        ])>{{ $entry->status->label() }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
