<?php

use App\Enums\ConcernFlag;
use App\Enums\EntryStatus;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\OwnerReviewService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('仕訳')] class extends Component {
    #[Locked]
    public string $companyId = '';

    #[Locked]
    public string $entryId = '';

    public Company $company;

    public bool $showReturn = false;

    public string $returnReason = '';

    public function mount(Company $company, string $entry): void
    {
        $this->authorize('viewSources', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->entryId = $company->journalEntries()->findOrFail($entry)->id;
    }

    #[Computed]
    public function entry(): JournalEntry
    {
        /** @var JournalEntry $entry */
        $entry = $this->company->journalEntries()
            ->with(['lines.account', 'category', 'bankAccount', 'confirmer', 'sources'])
            ->findOrFail($this->entryId);

        return $entry;
    }

    public function returnToBookkeeper(OwnerReviewService $service): void
    {
        $this->authorize('returnToBookkeeper', $this->company);
        $this->resetErrorBag();

        /** @var User $user */
        $user = Auth::user();

        $service->returnToBookkeeper($this->company, $user, $this->entryId, $this->returnReason);

        $this->showReturn = false;
        $this->returnReason = '';
        unset($this->entry);
    }
}; ?>

@php($entry = $this->entry)

<section class="mx-auto max-w-3xl space-y-4 p-4 text-body text-ink">
    <header class="flex items-baseline justify-between border-b border-line pb-3">
        <h1 class="text-2xl font-bold text-ink">仕訳</h1>
        <a href="{{ route('company.entries.index', $company) }}" wire:navigate class="text-note font-bold text-brand-ink underline">仕訳の一覧へ</a>
    </header>

    <dl class="grid grid-cols-3 gap-2 rounded-xl border border-line bg-paper p-4">
        <dt class="text-ink-sub">状態</dt><dd class="col-span-2 font-bold">{{ $entry->status->label() }}</dd>
        <dt class="text-ink-sub">日付</dt><dd class="col-span-2">{{ $entry->entry_date->isoFormat('M月D日(ddd)') }}</dd>
        <dt class="text-ink-sub">取引先</dt><dd class="col-span-2">{{ $entry->vendor }}</dd>
        <dt class="text-ink-sub">分類</dt><dd class="col-span-2">{{ $entry->category?->name }}</dd>
        <dt class="text-ink-sub">支払い元</dt><dd class="col-span-2">{{ $entry->bankAccount?->name }}</dd>
        <dt class="text-ink-sub">摘要</dt><dd class="col-span-2">{{ $entry->description }}</dd>
        @forelse ($entry->lines as $line)
            <dt class="text-ink-sub">{{ $line->side->label() }}</dt><dd class="col-span-2">{{ $line->account?->name }} {{ number_format($line->amount_yen) }}円</dd>
        @empty
            <dt class="text-ink-sub">金額</dt><dd class="col-span-2 font-bold text-warn">金額を入れてください</dd>
        @endforelse
        @if ($entry->confirmed_at)
            <dt class="text-ink-sub">確定</dt><dd class="col-span-2">{{ $entry->confirmer?->name }}・{{ $entry->confirmed_at->isoFormat('M月D日(ddd) H:mm') }}</dd>
        @endif
    </dl>

    @if ($entry->status === EntryStatus::Returned)
        <p class="rounded-lg bg-ng-soft p-3 text-ng" data-test="entry-returned">社長から戻されました：{{ $entry->returned_reason }}</p>
    @endif

    @if ($entry->status === EntryStatus::Confirmed)
        @can('returnToBookkeeper', $company)
            @if (! $showReturn)
                <button type="button" wire:click="$set('showReturn', true)" class="rounded-lg border-2 border-ng bg-paper px-4 py-2 font-bold text-ng" data-test="entry-return">経理に戻す</button>
            @else
                <form wire:submit="returnToBookkeeper" class="space-y-2 rounded-xl border border-line bg-panel p-4">
                    <label class="block space-y-1">
                        <span class="font-bold">戻す理由（経理に届きます）</span>
                        <input type="text" wire:model="returnReason" maxlength="200" placeholder="例 領収書の金額と合っているか確かめて" class="w-full rounded-lg border border-line bg-paper px-3 py-2 text-body" data-test="return-reason">
                    </label>
                    @error('returnReason') <span class="block text-ng">{{ $message }}</span> @enderror
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-ink px-4 py-2 font-bold text-paper disabled:opacity-60">経理に戻す</button>
                        <button type="button" wire:click="$set('showReturn', false)" class="rounded-lg border border-line bg-paper px-4 py-2 font-bold text-ink">やめる</button>
                    </div>
                </form>
            @endif
        @endcan
    @endif

    @if (! empty($entry->concern_flags))
        <div class="flex flex-wrap gap-2">
            @foreach ($entry->concern_flags as $flag)
                <span class="rounded-full bg-warn-soft px-3 py-1 text-note font-bold text-warn">{{ ConcernFlag::tryFrom($flag)?->label() }}</span>
            @endforeach
        </div>
    @endif

    @foreach ($entry->sources as $source)
        @can('editDraft', $company)
            <a href="{{ route('company.sources.show', [$company, $source]) }}" wire:navigate class="inline-block font-bold text-brand-ink underline">根拠の写真と下書きを開く</a>
        @endcan
    @endforeach
</section>
