<?php

use App\Enums\ConcernFlag;
use App\Models\Company;
use App\Models\OwnerInsight;
use App\Models\User;
use App\Services\OwnerDashboard;
use App\Services\OwnerInsights;
use App\Services\OwnerReviewService;
use App\Support\OwnerConcerns;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public ?string $returningId = null;

    public string $returnReason = '';

    public ?string $notice = null;

    public int $noticeSeq = 0;

    public function mount(Company $company): void
    {
        $this->authorize('viewOwner', $company);

        $this->company = $company;
        $this->companyId = $company->id;
    }

    public function markSeen(OwnerReviewService $service, string $entryId): void
    {
        $this->authorize('viewOwner', $this->company);

        $service->markSeen($this->company, $entryId);

        $this->reviewed('見た印を付けました。');
    }

    public function startReturn(string $entryId): void
    {
        $this->authorize('returnToBookkeeper', $this->company);
        $this->resetErrorBag();

        $this->returningId = $entryId;
        $this->returnReason = '';
    }

    public function cancelReturn(): void
    {
        $this->resetErrorBag();
        $this->returningId = null;
        $this->returnReason = '';
    }

    public function returnToBookkeeper(OwnerReviewService $service): void
    {
        $this->authorize('returnToBookkeeper', $this->company);
        $this->resetErrorBag();

        if ($this->returningId === null) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();

        $service->returnToBookkeeper($this->company, $user, $this->returningId, $this->returnReason);

        $this->returningId = null;
        $this->returnReason = '';
        $this->reviewed('経理に戻しました。');
    }

    private function reviewed(string $notice): void
    {
        $this->notice = $notice;
        $this->noticeSeq++;
        unset($this->dashboard, $this->insights);
        $this->dispatch('concerns-changed', count: OwnerConcerns::count($this->company));
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

    /**
     * @return array{brief: OwnerInsight, forecast: OwnerInsight}
     */
    #[Computed]
    public function insights(): array
    {
        return app(OwnerInsights::class)->refresh($this->company, $this->dashboard);
    }

    public function man(?int $yen): string
    {
        return $yen === null ? '—' : number_format((int) round($yen / 10000)).'万円';
    }

    public function hasSales(): bool
    {
        foreach ($this->dashboard['months'] as $row) {
            if ($row['current'] !== null || $row['previous'] !== null || $row['before'] !== null) {
                return true;
            }
        }

        return false;
    }

    public function yen(?int $amount): string
    {
        return $amount === null ? '—' : number_format($amount).'円';
    }

    public function signedMan(?int $amount, ?int $pct = null): string
    {
        if ($amount === null) {
            return '—';
        }

        $man = (int) round(abs($amount) / 10000);
        $text = ($amount > 0 ? '+' : ($amount < 0 ? '−' : '±')).number_format($man).'万円';

        return $pct === null ? $text : $text.$this->pct($pct);
    }

    public function pct(int $pct): string
    {
        return '（'.($pct > 0 ? '+' : ($pct < 0 ? '−' : '±')).abs($pct).'%）';
    }

    public function flagLabel(string $flag): string
    {
        return ConcernFlag::tryFrom($flag)?->label() ?? $flag;
    }
}; ?>

@php
    $dashboard = $this->dashboard;
    $totals = $dashboard['totals'];
    $brief = $this->insights['brief'];
    $forecast = $this->insights['forecast'];
    $updating = $brief->isUpdating() || $forecast->isUpdating();
    $concernCount = $dashboard['concerns']->count();
    $b = $brief->output;
    $f = $forecast->output;
    $salesForecast = is_array($f) && is_array($f['sales'] ?? null) ? $f['sales'] : [];
    $hasPriorOnlyDiff = collect($dashboard['months'])->contains(fn (array $row): bool => $row['current'] === null && $row['yoy_diff'] !== null);
@endphp

<section @if ($updating) wire:poll.2s @endif class="mx-auto max-w-5xl space-y-8 py-4 md:p-4 text-body text-ink">
    <header class="border-b border-line pb-3">
        <h1 class="text-xl font-bold text-ink">社長の画面</h1>
        <p class="mt-1 text-note text-ink-sub">{{ $company->name }}・今期は{{ $dashboard['periods']['current'] }}</p>
        <a href="{{ route('company.reports', $company) }}" wire:navigate class="mt-2 inline-block font-bold text-brand-ink underline" data-test="owner-reports-link">帳票（月別売上）の写真から数字を入れる</a>
    </header>

    {{-- ① 今月のひとこと（AI） --}}
    <section class="space-y-3 rounded-2xl border-2 border-ink bg-panel p-5 md:p-6" data-test="owner-brief">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-bold text-ink-sub">今月のひとこと（AI）</h2>
            @if ($brief->isUpdating())
                <span class="rounded bg-brand-soft px-2 py-0.5 text-note font-bold text-brand-ink" data-test="owner-brief-updating">更新中</span>
            @elseif ($brief->generated_at !== null)
                <span class="text-note text-ink-sub">{{ $brief->generated_at->isoFormat('M月D日(ddd) H:mm') }}に作成</span>
            @endif
        </div>
        @if (is_array($b))
            <p class="text-2xl font-bold leading-snug md:text-3xl" data-test="owner-brief-headline">{{ $b['headline'] ?? '' }}</p>
            @if (is_string($b['busy_alert'] ?? null) && $b['busy_alert'] !== '')
                <p class="font-bold text-ng" data-test="owner-brief-busy-alert">{{ $b['busy_alert'] }}</p>
            @endif
            <p data-test="owner-brief-check">確かめるとよい点：{{ $b['check'] ?? '' }}</p>
            <div x-data="{ open: false }">
                <button type="button" x-on:click="open = ! open" class="text-note font-bold text-ink-sub underline" x-bind:aria-expanded="open">見立てを読む</button>
                <p x-cloak x-show="open" class="mt-2 text-ink-sub" data-test="owner-brief-reason">見立て：{{ $b['reason'] ?? '' }}</p>
            </div>
        @elseif ($brief->isUpdating())
            <p class="text-ink-sub">AIが数字を見ています…</p>
        @else
            <p class="text-ink-sub">ひとことを作れませんでした。{{ $brief->error }}</p>
        @endif
        @if ($brief->error !== null && is_array($b) && ! $brief->isUpdating())
            <p class="text-note text-warn">最新の数字では作り直せませんでした（前回のひとことです）</p>
        @endif
    </section>

    {{-- ② 数字3つ --}}
    <section class="grid gap-4 lg:grid-cols-3" data-test="owner-numbers">
        <div class="rounded-2xl border border-line bg-panel p-5" data-test="owner-number-sales">
            <p class="text-note text-ink-sub">今期の売上{{ $totals['months_with_sales'] > 0 ? '（'.$totals['months_with_sales'].'か月）' : '' }}</p>
            @if ($totals['months_with_sales'] > 0)
                <p class="whitespace-nowrap text-2xl font-bold tabular-nums">{{ $this->man($totals['current']) }}</p>
                <p class="tabular-nums text-ink-sub">前期の同じ月より {{ $this->signedMan($totals['diff'], $totals['pct']) }}</p>
            @else
                <p class="text-ink-sub">数字がまだありません</p>
            @endif
        </div>

        <div class="rounded-2xl border border-line bg-panel p-5" data-test="owner-forecast-sales">
            <p class="text-note text-ink-sub">
                {{ is_array($f) ? ($f['month'] ?? '').'月の売上' : '来月の売上' }}の見込み（AI）
                @if ($forecast->isUpdating())
                    <span class="ml-1 rounded bg-brand-soft px-2 py-0.5 font-bold text-brand-ink" data-test="owner-forecast-updating">更新中</span>
                @endif
            </p>
            @if (is_int($salesForecast['median_yen'] ?? null))
                <p class="whitespace-nowrap text-2xl font-bold tabular-nums">だいたい {{ $this->man($salesForecast['median_yen']) }}</p>
                <p class="tabular-nums text-ink-sub">8割の確からしさで <span class="whitespace-nowrap">{{ $this->man($salesForecast['low_yen'] ?? null) }}〜{{ $this->man($salesForecast['high_yen'] ?? null) }}</span></p>
            @elseif ($forecast->isUpdating())
                <p class="text-ink-sub">AIが見込みを出しています…</p>
            @elseif (is_array($f))
                <p class="text-ink-sub">数字が足りないため、まだ出せません</p>
            @else
                <p class="text-ink-sub">見込みを出せませんでした。{{ $forecast->error }}</p>
            @endif
        </div>

        @if ($concernCount > 0)
            <a href="#owner-concerns" class="block rounded-2xl border-2 border-warn bg-warn-soft p-5 hover:border-ink" data-test="owner-number-concerns">
                <p class="text-note text-ink-sub">気になる仕訳（まだ見ていない）</p>
                <p class="text-2xl font-bold tabular-nums">{{ $concernCount }}件</p>
                <p class="text-ink-sub underline">下で見る</p>
            </a>
        @else
            <div class="rounded-2xl border border-line bg-panel p-5" data-test="owner-number-concerns">
                <p class="text-note text-ink-sub">気になる仕訳（まだ見ていない）</p>
                <p class="text-2xl font-bold text-ok">0件 ✓</p>
            </div>
        @endif
    </section>

    @if ($notice !== null)
        <p wire:key="notice-{{ $noticeSeq }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 3000)" x-show="show" x-transition.opacity.duration.500ms
           class="rounded-lg bg-ok-soft px-3 py-2 font-bold text-ok" role="status" data-test="owner-notice">{{ $notice }}</p>
    @endif

    {{-- 気になる仕訳（0件なら出さない） --}}
    @if ($concernCount > 0)
        <section id="owner-concerns" class="scroll-mt-4 space-y-4" data-test="owner-concerns">
            <h2 class="text-xl font-bold">気になる仕訳（<span data-test="owner-concern-count">{{ $concernCount }}</span>件）</h2>
            @foreach ($dashboard['concerns'] as $entry)
                <div wire:key="concern-{{ $entry->id }}" class="space-y-3 rounded-xl border border-line bg-panel p-4" data-test="owner-concern">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <a href="{{ route('company.entries.show', [$company, $entry]) }}" wire:navigate class="hover:underline">
                            <span class="font-bold">{{ $entry->entry_date->isoFormat('M月D日(ddd)') }}　{{ $entry->vendor ?? '取引先なし' }}</span>
                            <span class="ml-2 whitespace-nowrap tabular-nums">{{ $this->yen($entry->amountYen()) }}</span>
                            <span class="ml-2 text-ink-sub">{{ $entry->category?->name }}</span>
                        </a>
                        <span class="flex flex-wrap gap-1">
                            @foreach ($entry->concern_flags ?? [] as $flag)
                                <span class="rounded bg-warn-soft px-2 py-0.5 text-note font-bold text-warn">{{ $this->flagLabel($flag) }}</span>
                            @endforeach
                        </span>
                    </div>
                    @if ($returningId === $entry->id)
                        <form wire:submit="returnToBookkeeper" class="space-y-2">
                            <label class="block space-y-1">
                                <span class="font-bold">戻す理由（経理に届きます）</span>
                                <input type="text" wire:model="returnReason" maxlength="200" placeholder="例 領収書の金額と合っているか確かめて" class="w-full rounded-lg border border-line bg-paper px-3 py-2 text-body" data-test="return-reason">
                            </label>
                            @error('returnReason') <span class="block text-ng">{{ $message }}</span> @enderror
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-ink px-4 py-2 font-bold text-paper disabled:opacity-60" data-test="return-submit">経理に戻す</button>
                                <button type="button" wire:click="cancelReturn" class="rounded-lg border border-line bg-paper px-4 py-2 font-bold text-ink">やめる</button>
                            </div>
                        </form>
                    @else
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="markSeen('{{ $entry->id }}')" wire:loading.attr="disabled" class="rounded-lg bg-ink px-4 py-2 font-bold text-paper disabled:opacity-60" data-test="concern-seen">見た</button>
                            @can('returnToBookkeeper', $company)
                                <button type="button" wire:click="startReturn('{{ $entry->id }}')" class="rounded-lg border-2 border-ink bg-paper px-4 py-2 font-bold text-ink" data-test="concern-return">経理に戻す</button>
                            @endcan
                        </div>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    {{-- ③ 三期比較 --}}
    <section class="space-y-4" data-test="owner-three-period">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-xl font-bold">三期比較（売上）</h2>
            <p class="text-note text-ink-sub"><span class="inline-block size-3 rounded-sm bg-brand-soft align-middle"></span> 薄い帯は忙しい月（会社設定）</p>
        </div>

        @if ($this->hasSales())
            <div wire:ignore x-data="ownerChart(@js($this->chartData()))" class="h-72 rounded-xl border border-line bg-panel p-3 md:h-80 md:p-4" data-test="owner-chart">
                <canvas x-ref="canvas" aria-label="三期比較の棒グラフ" role="img"></canvas>
            </div>

            <div x-data="{ open: false }">
                <button type="button" x-on:click="open = ! open" class="font-bold text-ink underline" x-bind:aria-expanded="open" data-test="owner-table-toggle">
                    <span x-text="open ? '表をたたむ' : '表で見る'">表で見る</span>
                </button>
                <div x-cloak x-show="open" class="mt-3 space-y-2" data-test="owner-table">
                    <div class="overflow-x-auto rounded-xl border border-line">
                        <table class="w-full whitespace-nowrap text-right tabular-nums">
                            <thead class="bg-panel text-note text-ink-sub">
                                <tr>
                                    <th class="px-1.5 py-2 md:px-3 text-left">月</th>
                                    <th class="px-1.5 py-2 md:px-3">今期</th>
                                    <th class="px-1.5 py-2 md:px-3">前期</th>
                                    <th class="px-1.5 py-2 md:px-3">差</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($dashboard['months'] as $row)
                                    <tr data-month="{{ $row['month'] }}" data-busy="{{ $row['busy'] ? '1' : '0' }}">
                                        <td class="px-1.5 py-2 text-left font-bold md:px-3">
                                            {{ $row['month'] }}月
                                            @if ($row['busy'])
                                                <span class="ml-1 text-note text-ink-sub" title="忙しい月">忙</span>
                                            @endif
                                        </td>
                                        <td class="px-1.5 py-2 md:px-3 font-bold">{{ $this->man($row['current']) }}</td>
                                        <td class="px-1.5 py-2 md:px-3">{{ $this->man($row['previous']) }}</td>
                                        <td @class(['px-1.5 py-2 md:px-3', 'font-bold text-ng' => $row['busy_but_lower']])>
                                            {{ $this->signedMan($row['yoy_diff']) }}<span class="block text-note md:ml-1 md:inline">{{ $row['yoy_pct'] !== null ? $this->pct($row['yoy_pct']) : '' }}{{ $row['current'] === null && $row['yoy_diff'] !== null ? '＊' : '' }}</span>
                                            @if ($row['busy_but_lower'])
                                                <span class="block whitespace-normal text-note" data-test="busy-but-lower">忙しい月なのに前年より少ない</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($hasPriorOnlyDiff)
                        <p class="text-note text-ink-sub" data-test="owner-table-note">＊今期の数字がまだない月の差は、前期と前々期の差です。</p>
                    @endif
                </div>
            </div>
        @else
            <div class="flex h-40 flex-col items-center justify-center gap-1 rounded-xl border border-line bg-panel p-4 text-center" data-test="owner-chart-empty">
                <p class="font-bold text-ink-sub">数字がまだありません</p>
                <p class="text-note text-ink-sub">売上の三期比較を取り込むと、ここにグラフが出ます</p>
            </div>
        @endif
    </section>

    {{-- くわしく見る（経費と残ったお金・分類ごとの経費・来月の経費の見込み・売上の柱） --}}
    <section x-data="{ open: $persist(false).as('torudake-owner-details') }" class="space-y-6 border-t border-line pt-4" data-test="owner-details">
        <button type="button" x-on:click="open = ! open" class="text-lg font-bold text-ink underline" x-bind:aria-expanded="open" data-test="owner-details-toggle">
            <span x-text="open ? 'くわしく見るをたたむ' : 'くわしく見る'">くわしく見る</span>
        </button>

        <div x-cloak x-show="open" class="space-y-8">
            <section class="space-y-4" data-test="owner-expenses">
                <h2 class="text-xl font-bold">今期の経費と残ったお金</h2>
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="overflow-x-auto rounded-xl border border-line md:col-span-2">
                        <table class="w-full whitespace-nowrap text-right tabular-nums">
                            <thead class="bg-panel text-note text-ink-sub">
                                <tr>
                                    <th class="px-1.5 py-2 md:px-3 text-left">月</th>
                                    <th class="px-1.5 py-2 md:px-3">売上</th>
                                    <th class="px-1.5 py-2 md:px-3">経費（確定）</th>
                                    <th class="px-1.5 py-2 md:px-3">売上 − 経費</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @php($expenseRows = array_filter($dashboard['months'], fn (array $row): bool => $row['current'] !== null || $row['expense'] !== null))
                                @if ($expenseRows === [])
                                    <tr><td colspan="4" class="px-3 py-4 text-center text-ink-sub">数字がまだありません</td></tr>
                                @endif
                                @foreach ($expenseRows as $row)
                                    <tr data-expense-month="{{ $row['month'] }}">
                                        <td class="px-1.5 py-2 text-left font-bold md:px-3">{{ $row['month'] }}月</td>
                                        <td class="px-1.5 py-2 md:px-3">{{ $this->yen($row['current']) }}</td>
                                        <td class="px-1.5 py-2 md:px-3">{{ $this->yen($row['expense']) }}</td>
                                        <td class="px-1.5 py-2 md:px-3 font-bold">{{ $this->yen($row['left']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="rounded-xl border border-line p-4">
                        <p class="text-note text-ink-sub">分類ごとの経費（今期）</p>
                        @forelse ($dashboard['expense_by_category'] as $category)
                            <p class="flex justify-between gap-2 py-1"><span>{{ $category['name'] }}</span><span class="whitespace-nowrap font-bold tabular-nums">{{ $this->yen($category['amount_yen']) }}</span></p>
                        @empty
                            <p class="py-1 text-ink-sub">まだ確定した経費はありません</p>
                        @endforelse
                    </div>
                </div>
                <p class="text-note text-ink-sub">経費はアプリを使い始めた月から。経費のない月は「—」。</p>
            </section>

            @if (is_array($f))
                @php($expenseForecast = is_array($f['expense'] ?? null) ? $f['expense'] : [])
                <section class="space-y-2" data-test="owner-forecast-expense">
                    <h2 class="text-xl font-bold">{{ $f['month'] ?? '' }}月の経費の見込み（AI）</h2>
                    @if (is_int($expenseForecast['median_yen'] ?? null))
                        <p class="tabular-nums"><span class="font-bold">だいたい {{ $this->man($expenseForecast['median_yen']) }}</span>　8割の確からしさで {{ $this->man($expenseForecast['low_yen'] ?? null) }}〜{{ $this->man($expenseForecast['high_yen'] ?? null) }}</p>
                    @else
                        <p class="text-ink-sub">数字が足りないため、まだ出せません</p>
                    @endif
                    <p class="text-note text-ink-sub">見込みの根拠：{{ $f['note'] ?? '' }}</p>
                </section>
            @endif

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
                                <span class="shrink-0 whitespace-nowrap text-right tabular-nums"><span class="font-bold">{{ $customer['share_pct'] }}%</span> <span class="text-note text-ink-sub">{{ $this->man($customer['amount_yen']) }}</span></span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </section>

    <footer class="flex flex-wrap gap-4 border-t border-line pt-4">
        <a href="{{ route('company.entries.index', $company) }}" wire:navigate class="font-bold text-ink underline">仕訳の一覧</a>
        @can('exportExcel', $company)
            <a href="{{ route('company.workbook', $company) }}" class="font-bold text-ink underline">Excel をダウンロード</a>
        @endcan
    </footer>
</section>
