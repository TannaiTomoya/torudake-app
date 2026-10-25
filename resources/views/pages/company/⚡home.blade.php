<?php

use App\Models\Company;
use App\Support\OwnerConcerns;
use App\Support\RemainingCount;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('ホーム')] class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public function mount(Company $company): void
    {
        $this->authorize('viewHome', $company);

        $this->company = $company;
        $this->companyId = $company->id;
    }

    /** computed なので、画面の外から呼べない（2段階認証の前の人に件数を出さない） */
    #[Computed]
    public function remaining(): int
    {
        return RemainingCount::for($this->company);
    }

    #[Computed]
    public function previousMonthsRemaining(): int
    {
        return RemainingCount::fromPreviousMonths($this->company);
    }

    #[Computed]
    public function concerns(): int
    {
        return OwnerConcerns::count($this->company);
    }
}; ?>

<section class="mx-auto max-w-3xl space-y-6 p-4 text-body text-ink">
    <header class="border-b border-line pb-4">
        <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold text-ink">{{ $company->name }}</h1>
            @if ($company->is_demo)
                <span class="rounded bg-brand-soft px-2 py-0.5 text-note font-bold text-brand-ink">デモ</span>
            @endif
        </div>
        <p class="mt-1 text-note text-ink-sub">決算月：{{ $company->fiscal_year_end_month }}月</p>
    </header>

    @if (auth()->user()?->two_factor_confirmed_at === null)
        @include('partials.two-factor-first')
    @else
    <p class="text-note font-bold text-ink-sub">次にやること</p>

    @php($homeMembership = auth()->user()?->activeMembershipFor($company))
    @if ($homeMembership?->role->prefersOwnerHome() === true && auth()->user()->can('viewOwner', $company))
        @php($concerns = $this->concerns)
        <a href="{{ route('company.owner', $company) }}" wire:navigate data-test="owner-link"
           @class(['block space-y-4 rounded-2xl border-2 p-8 hover:border-brand', 'border-warn bg-warn-soft' => $concerns > 0, 'border-ink bg-panel' => $concerns === 0])>
            @if ($concerns > 0)
                <p class="text-3xl font-bold text-ink">気になる仕訳が <span class="text-5xl">{{ $concerns }}</span> 件あります</p>
                <p class="text-ink-sub">社長の画面で見て、違えば理由を付けて経理に戻す</p>
            @else
                <p class="text-3xl font-bold text-ink">社長の画面を見る</p>
                <p class="text-ink-sub">三期比較・今月のひとこと・来月の見込み・売上の柱</p>
            @endif
            <span class="inline-block rounded-xl bg-ink px-6 py-3 text-lg font-bold text-paper">社長の画面を開く</span>
        </a>
        <nav aria-label="ほかの作業" class="flex flex-wrap gap-4 text-body font-bold text-brand-ink underline">
            @can('submit', $company)
                <a href="{{ route('company.submit', $company) }}" wire:navigate>レシートを送る</a>
            @endcan
            @can('editDraft', $company)
                <a href="{{ route('company.sources.index', $company) }}" wire:navigate>直して保存する</a>
            @endcan
            @can('viewAi', $company)
                <a href="{{ route('company.ai', $company) }}" wire:navigate>AIの記録</a>
            @endcan
        </nav>
    @else
        @can('editDraft', $company)
            @php($remaining = $this->remaining)
            @if ($remaining > 0)
                <a href="{{ route('company.sources.index', $company) }}" wire:navigate data-test="remaining"
                   class="block space-y-4 rounded-2xl border-2 border-brand bg-brand-soft p-8 hover:border-ink">
                    <p class="text-ink-sub">未処理 あと</p>
                    <p class="text-7xl font-bold text-ink">{{ $remaining }}<span class="text-3xl">件</span></p>
                    @if ($this->previousMonthsRemaining > 0)
                        <p class="font-bold text-warn">前月以前の未処理 {{ $this->previousMonthsRemaining }}件を含みます</p>
                    @endif
                    <p class="text-ink-sub">AIの下書きを確かめて、直して保存する</p>
                    <span class="inline-block rounded-xl bg-ink px-6 py-3 text-lg font-bold text-paper">直して保存する</span>
                </a>
            @else
                <a href="{{ route('company.submit', $company) }}" wire:navigate data-test="remaining"
                   class="block space-y-4 rounded-2xl border-2 border-ok bg-ok-soft p-8 hover:border-ink">
                    <p class="text-3xl font-bold text-ok">未処理の帳簿ができました</p>
                    <p class="text-ink-sub">新しいレシートが届いたら、ここに件数が出ます</p>
                    <span class="inline-block rounded-xl bg-ink px-6 py-3 text-lg font-bold text-paper">レシートを送る</span>
                </a>
            @endif
            @can('viewOwner', $company)
                <nav aria-label="ほかの作業" class="flex flex-wrap gap-4 text-body font-bold text-brand-ink underline">
                    <a href="{{ route('company.owner', $company) }}" wire:navigate data-test="owner-shortcut">社長の画面</a>
                    @can('viewAi', $company)
                        <a href="{{ route('company.ai', $company) }}" wire:navigate>AIの記録</a>
                    @endcan
                </nav>
            @endcan
        @else
            <a href="{{ route('company.entries.index', $company) }}" wire:navigate data-test="entries-link"
               class="block space-y-4 rounded-2xl border-2 border-ink bg-panel p-8 hover:border-brand">
                <p class="text-3xl font-bold text-ink">仕訳の一覧を見る</p>
                <p class="text-ink-sub">確定した仕訳と、AIの下書き</p>
                <span class="inline-block rounded-xl bg-ink px-6 py-3 text-lg font-bold text-paper">仕訳の一覧を開く</span>
            </a>
        @endcan
    @endif
    @endif
</section>
