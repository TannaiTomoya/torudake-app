<?php

use App\Models\Company;
use App\Support\RemainingCount;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public function mount(Company $company): void
    {
        $this->authorize('viewHome', $company);

        $this->company = $company;
        $this->companyId = $company->id;
    }

    public function remaining(): int
    {
        return RemainingCount::for($this->company);
    }
}; ?>

<section class="mx-auto max-w-4xl space-y-6 p-4 text-body text-ink">
    <header class="flex items-center justify-between border-b border-line pb-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold text-ink">{{ $company->name }}</h1>
                @if ($company->is_demo)
                    <span class="rounded bg-brand-soft px-2 py-0.5 text-note font-bold text-brand-ink">デモ</span>
                @endif
            </div>
            <p class="mt-1 text-note text-ink-sub">決算月：{{ $company->fiscal_year_end_month }}月</p>
        </div>
    </header>

    @can('viewOwner', $company)
        <a href="{{ route('company.owner', $company) }}" wire:navigate data-test="owner-link" class="block rounded-xl border-2 border-ink bg-panel p-5 hover:border-brand">
            <div class="text-xl font-bold text-ink">社長の画面</div>
            <p class="mt-1 text-note text-ink-sub">三期比較・気になる仕訳・売上の柱</p>
        </a>
    @endcan

    @can('viewSources', $company)
        @php($remaining = $this->remaining())
        <a href="{{ route('company.sources.index', $company) }}" wire:navigate data-test="remaining"
           @class(['block rounded-xl p-6 text-center', 'bg-brand-soft' => $remaining > 0, 'bg-ok-soft' => $remaining === 0])>
            @if ($remaining > 0)
                <p class="text-ink-sub">今月 あと</p>
                <p class="text-5xl font-bold text-ink">{{ $remaining }}<span class="text-2xl">件</span></p>
            @else
                <p class="text-2xl font-bold text-ok">今月の帳簿ができました</p>
            @endif
        </a>
    @endcan

    <div class="grid gap-4 sm:grid-cols-2">
        @can('submit', $company)
            <a href="{{ route('company.submit', $company) }}" wire:navigate class="block rounded-xl border border-line bg-panel p-5 hover:border-brand">
                <div class="font-bold text-ink">レシートを送る</div>
                <p class="mt-1 text-note text-ink-sub">写真を撮って送るだけ</p>
            </a>
        @endcan

        @can('viewSources', $company)
            <a href="{{ route('company.sources.index', $company) }}" wire:navigate class="block rounded-xl border border-line bg-panel p-5 hover:border-brand">
                <div class="font-bold text-ink">原資料の一覧</div>
                <p class="mt-1 text-note text-ink-sub">AIの下書きを直して確定する</p>
            </a>
        @endcan

        @can('viewSources', $company)
            <a href="{{ route('company.entries.index', $company) }}" wire:navigate class="block rounded-xl border border-line bg-panel p-5 hover:border-brand">
                <div class="font-bold text-ink">仕訳の一覧</div>
                <p class="mt-1 text-note text-ink-sub">確定した仕訳と下書き</p>
            </a>
        @endcan

        @can('exportExcel', $company)
            <a href="{{ route('company.workbook', $company) }}" data-test="workbook-download" class="block rounded-xl border border-line bg-panel p-5 hover:border-brand">
                <div class="font-bold text-ink">Excel をダウンロード</div>
                <p class="mt-1 text-note text-ink-sub">確定した仕訳がすべて入った1冊</p>
            </a>
        @endcan

        @can('manageSettings', $company)
            <a href="{{ route('company.settings', $company) }}" wire:navigate class="block rounded-xl border border-line bg-panel p-5 hover:border-brand">
                <div class="font-bold text-ink">会社設定</div>
                <p class="mt-1 text-note text-ink-sub">分類・勘定科目・口座・メンバー</p>
            </a>
        @endcan
    </div>
</section>
