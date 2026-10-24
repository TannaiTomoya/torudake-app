<?php

use App\Models\Company;
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
                <p class="mt-1 text-note text-ink-sub">送られた写真とAIの下書き</p>
            </a>
        @endcan

        @can('viewClose', $company)
            <a href="{{ route('company.close', [$company, now()->format('Y-m')]) }}" wire:navigate class="block rounded-xl border border-line bg-panel p-5 hover:border-brand">
                <div class="font-bold text-ink">締めナビ</div>
                <p class="mt-1 text-note text-ink-sub">今月の残りと月次集計</p>
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
