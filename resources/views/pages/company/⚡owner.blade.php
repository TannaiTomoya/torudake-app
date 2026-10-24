<?php

use App\Enums\ConcernFlag;
use App\Models\Company;
use App\Services\OwnerDashboard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public function mount(Company $company): void
    {
        $this->authorize('viewOwner', $company);

        $this->company = $company;
        $this->companyId = $company->id;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function dashboard(): array
    {
        return app(OwnerDashboard::class)->build($this->company);
    }

    /**
     * @return array{labels: list<string>, busy: list<bool>, datasets: list<array{label: string, color: string, values: list<int|null>}>}
     */
    public function chartData(): array
    {
        $months = $this->dashboard['months'];
        $periods = $this->dashboard['periods'];

        return [
            'labels' => array_map(fn (array $row): string => $row['month'].'月', $months),
            'busy' => array_column($months, 'busy'),
            'datasets' => [
                ['label' => '今期（'.$periods['current'].'）', 'color' => 'brand', 'values' => array_column($months, 'current')],
                ['label' => '前期（'.$periods['previous'].'）', 'color' => 'ink', 'values' => array_column($months, 'previous')],
                ['label' => '前々期（'.$periods['before'].'）', 'color' => 'muted', 'values' => array_column($months, 'before')],
            ],
        ];
    }

    public function yen(?int $amount): string
    {
        return $amount === null ? '—' : number_format($amount).'円';
    }

    public function signedYen(?int $amount, ?int $pct = null): string
    {
        if ($amount === null) {
            return '—';
        }

        $text = ($amount > 0 ? '+' : ($amount < 0 ? '−' : '±')).number_format(abs($amount)).'円';

        return $pct === null ? $text : $text.'（'.($pct > 0 ? '+' : ($pct < 0 ? '−' : '±')).abs($pct).'%）';
    }

    public function flagLabel(string $flag): string
    {
        return ConcernFlag::tryFrom($flag)?->label() ?? $flag;
    }
}; ?>

@php
    $dashboard = $this->dashboard;
    $totals = $dashboard['totals'];
@endphp

<section class="mx-auto max-w-5xl space-y-8 p-4 text-body text-ink">
    <header class="flex flex-wrap items-end justify-between gap-2 border-b border-line pb-4">
        <div>
            <h1 class="text-2xl font-bold text-ink">社長の画面</h1>
            <p class="mt-1 text-note text-ink-sub">{{ $company->name }}・今期は{{ $dashboard['periods']['current'] }}</p>
        </div>
        @if ($totals['months_with_sales'] > 0)
            <p class="font-bold" data-test="owner-total">
                今期{{ $totals['months_with_sales'] }}か月の売上 {{ $this->yen($totals['current']) }}
                <span class="{{ $totals['diff'] < 0 ? 'text-ng' : 'text-ok' }}">前期の同じ月より {{ $this->signedYen($totals['diff'], $totals['pct']) }}</span>
            </p>
        @endif
    </header>

    {{-- ② 三期比較 --}}
    <section class="space-y-4" data-test="owner-three-period">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-xl font-bold">三期比較（売上）</h2>
            <p class="text-note text-ink-sub"><span class="inline-block size-3 rounded-sm bg-brand-soft align-middle"></span> 色のついた月は忙しい月（会社設定）</p>
        </div>

        <div wire:ignore x-data="ownerChart(@js($this->chartData()))" class="h-80 rounded-xl border border-line bg-panel p-4">
            <canvas x-ref="canvas" aria-label="三期比較の棒グラフ" role="img"></canvas>
        </div>

        <div class="overflow-x-auto rounded-xl border border-line">
            <table class="w-full text-right">
                <thead class="bg-panel text-note text-ink-sub">
                    <tr>
                        <th class="px-3 py-2 text-left">月</th>
                        <th class="px-3 py-2">今期</th>
                        <th class="px-3 py-2">前期</th>
                        <th class="px-3 py-2">前々期</th>
                        <th class="px-3 py-2">前年同月との差</th>
                        <th class="px-3 py-2">3期の平均</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($dashboard['months'] as $row)
                        <tr @class(['bg-brand-soft' => $row['busy']]) data-month="{{ $row['month'] }}" data-busy="{{ $row['busy'] ? '1' : '0' }}">
                            <td class="px-3 py-2 text-left font-bold">
                                {{ $row['month'] }}月
                                @if ($row['busy'])
                                    <span class="ml-1 text-note text-brand-ink">忙しい月</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-bold">{{ $this->yen($row['current']) }}</td>
                            <td class="px-3 py-2">{{ $this->yen($row['previous']) }}</td>
                            <td class="px-3 py-2 text-ink-sub">{{ $this->yen($row['before']) }}</td>
                            <td @class(['px-3 py-2', 'text-ng font-bold' => ($row['yoy_diff'] ?? 0) < 0])>
                                {{ $this->signedYen($row['yoy_diff'], $row['yoy_pct']) }}
                                @if ($row['current'] === null && $row['yoy_diff'] !== null)
                                    <span class="block text-note text-ink-sub">前期と前々期</span>
                                @endif
                                @if ($row['busy_but_lower'])
                                    <span class="block text-note text-ng" data-test="busy-but-lower">忙しい月なのに前年より少ない</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-ink-sub">{{ $this->yen($row['average']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- ② 今期の経費・残ったお金 --}}
    <section class="space-y-4" data-test="owner-expenses">
        <h2 class="text-xl font-bold">今期の経費と残ったお金</h2>
        <div class="grid gap-4 md:grid-cols-3">
            <div class="overflow-x-auto rounded-xl border border-line md:col-span-2">
                <table class="w-full text-right">
                    <thead class="bg-panel text-note text-ink-sub">
                        <tr>
                            <th class="px-3 py-2 text-left">月</th>
                            <th class="px-3 py-2">売上</th>
                            <th class="px-3 py-2">経費（確定）</th>
                            <th class="px-3 py-2">売上 − 経費</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($dashboard['months'] as $row)
                            @continue($row['current'] === null && $row['expense'] === null)
                            <tr data-expense-month="{{ $row['month'] }}">
                                <td class="px-3 py-2 text-left font-bold">{{ $row['month'] }}月</td>
                                <td class="px-3 py-2">{{ $this->yen($row['current']) }}</td>
                                <td class="px-3 py-2">{{ $this->yen($row['expense']) }}</td>
                                <td @class(['px-3 py-2 font-bold', 'text-ng' => ($row['left'] ?? 0) < 0])>{{ $this->yen($row['left']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="rounded-xl border border-line p-4">
                <p class="text-note text-ink-sub">分類ごとの経費（今期）</p>
                @forelse ($dashboard['expense_by_category'] as $category)
                    <p class="flex justify-between gap-2 py-1"><span>{{ $category['name'] }}</span><span class="font-bold">{{ $this->yen($category['amount_yen']) }}</span></p>
                @empty
                    <p class="py-1 text-ink-sub">まだ確定した経費はありません</p>
                @endforelse
            </div>
        </div>
        <p class="text-note text-ink-sub">経費はアプリを使い始めた月から。経費のない月は「—」。</p>
    </section>

    {{-- ③ 気になる仕訳 --}}
    <section class="space-y-4" data-test="owner-concerns">
        <h2 class="text-xl font-bold">気になる仕訳（{{ $dashboard['concerns']->count() }}件）</h2>
        @forelse ($dashboard['concerns'] as $entry)
            <a href="{{ route('company.entries.show', [$company, $entry]) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line bg-panel p-4 hover:border-brand" data-test="owner-concern">
                <span>
                    <span class="font-bold">{{ $entry->entry_date->isoFormat('M月D日(ddd)') }}　{{ $entry->vendor ?? '取引先なし' }}</span>
                    <span class="ml-2">{{ $this->yen($entry->amountYen()) }}</span>
                    <span class="ml-2 text-ink-sub">{{ $entry->category?->name }}</span>
                </span>
                <span class="flex flex-wrap gap-1">
                    @foreach ($entry->concern_flags ?? [] as $flag)
                        <span class="rounded bg-warn-soft px-2 py-0.5 text-note font-bold text-warn">{{ $this->flagLabel($flag) }}</span>
                    @endforeach
                </span>
            </a>
        @empty
            <p class="text-ink-sub">気になる仕訳はありません</p>
        @endforelse
    </section>

    {{-- ⑤ 売上の柱 --}}
    <section class="space-y-4" data-test="owner-customers">
        <h2 class="text-xl font-bold">売上の柱</h2>
        @if ($dashboard['customers'] === null)
            <p class="text-ink-sub">顧客別の売上はまだ取り込まれていません</p>
        @else
            <p class="text-note text-ink-sub">{{ $dashboard['customers']['period_from'] }}〜{{ $dashboard['customers']['period_to'] }}・取引先の名前は保存していません</p>
            <div class="space-y-2">
                @foreach ($dashboard['customers']['top'] as $customer)
                    <div class="flex items-center gap-3" data-test="owner-customer">
                        <span class="w-12 shrink-0 font-bold">{{ $customer['label'] }}</span>
                        <div class="h-6 flex-1 overflow-hidden rounded bg-panel">
                            <div class="h-full rounded bg-brand" style="width: {{ $customer['share_pct'] }}%"></div>
                        </div>
                        <span class="w-40 shrink-0 text-right"><span class="font-bold">{{ $customer['share_pct'] }}%</span> <span class="text-note text-ink-sub">{{ $this->yen($customer['amount_yen']) }}</span></span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <footer class="flex flex-wrap gap-4 border-t border-line pt-4">
        <a href="{{ route('company.entries.index', $company) }}" wire:navigate class="font-bold text-ink underline">仕訳の一覧</a>
        @can('exportExcel', $company)
            <a href="{{ route('company.workbook', $company) }}" class="font-bold text-ink underline">Excel をダウンロード</a>
        @endcan
    </footer>
</section>
