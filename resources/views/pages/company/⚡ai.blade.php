<?php

use App\Models\Company;
use App\Services\AiRecordSummary;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('AIの記録')] class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    #[Url]
    public string $month = '';

    public function mount(Company $company): void
    {
        $this->authorize('viewAi', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->month = $this->monthStart()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->authorize('viewAi', $this->company);

        $this->month = $this->monthStart()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->authorize('viewAi', $this->company);

        if ($this->isCurrentMonth()) {
            return;
        }

        $this->month = $this->monthStart()->addMonth()->format('Y-m');
    }

    public function monthStart(): CarbonImmutable
    {
        $thisMonth = CarbonImmutable::now()->startOfMonth();

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            return $thisMonth;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $this->month);

        return $month === false || $month->greaterThan($thisMonth) ? $thisMonth : $month;
    }

    public function isCurrentMonth(): bool
    {
        return $this->monthStart()->equalTo(CarbonImmutable::now()->startOfMonth());
    }
}; ?>

@php
    $start = $this->monthStart();
    $summary = app(AiRecordSummary::class)->for($company, $start);
@endphp

<section class="mx-auto max-w-5xl space-y-8 py-4 md:p-4 text-body text-ink">
    <header class="flex flex-wrap items-end justify-between gap-3 border-b border-line pb-3">
        <div>
            <h1 class="text-xl font-bold text-ink">AIの記録</h1>
            <p class="mt-1 text-note text-ink-sub">{{ $company->name }}</p>
        </div>
        <div class="flex items-center gap-2" data-test="ai-month">
            <button type="button" wire:click="previousMonth" class="rounded-lg border border-line bg-paper px-3 py-1 font-bold" aria-label="前の月">‹</button>
            <span class="min-w-28 text-center font-bold tabular-nums" data-test="ai-month-label">{{ $start->isoFormat('YYYY年M月') }}</span>
            <button type="button" wire:click="nextMonth" @disabled($this->isCurrentMonth()) class="rounded-lg border border-line bg-paper px-3 py-1 font-bold disabled:opacity-40" aria-label="次の月">›</button>
        </div>
    </header>

    {{-- その月の結論（デモの S5） --}}
    <section class="space-y-3 rounded-2xl border-2 border-ink bg-panel p-5 md:p-6" data-test="ai-headline">
        @if ($summary['ai_entries'] > 0)
            <p class="text-2xl font-bold leading-snug md:text-3xl">
                AIが作った仕訳 <span class="tabular-nums" data-test="ai-entries">{{ number_format($summary['ai_entries']) }}</span>件のうち、そのまま確定 <span class="tabular-nums" data-test="ai-unchanged">{{ number_format($summary['unchanged']) }}</span>件（<span data-test="ai-unchanged-pct">{{ $summary['unchanged_pct'] }}</span>%）
            </p>
        @else
            <p class="text-2xl font-bold leading-snug md:text-3xl">この月は、AIが作って確定した仕訳がまだありません</p>
        @endif
        @if ($summary['top_reason'] !== null)
            <p class="font-bold" data-test="ai-top-reason">一番多い直し：{{ $summary['top_reason']['label'] }}（{{ $summary['top_reason']['count'] }}件）</p>
        @else
            <p class="text-ink-sub">この月の直しはまだありません</p>
        @endif
        <p class="text-ink-sub">直した記録は、次の Python 編で規則にする材料になります。</p>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- 直した内訳 --}}
        <section class="space-y-3" data-test="ai-corrections">
            <h2 class="text-xl font-bold">直した内訳（{{ number_format($summary['corrections']) }}か所）</h2>
            @if ($summary['corrections'] === 0)
                <p class="text-ink-sub">まだありません</p>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-line bg-paper p-4">
                        <h3 class="mb-2 text-note font-bold text-ink-sub">理由</h3>
                        <dl class="space-y-1">
                            @foreach ($summary['reasons'] as $row)
                                <div class="flex justify-between gap-3"><dt>{{ $row['label'] }}</dt><dd class="font-bold tabular-nums">{{ $row['count'] }}件</dd></div>
                            @endforeach
                        </dl>
                    </div>
                    <div class="rounded-xl border border-line bg-paper p-4">
                        <h3 class="mb-2 text-note font-bold text-ink-sub">直した欄</h3>
                        <dl class="space-y-1">
                            @foreach ($summary['fields'] as $row)
                                <div class="flex justify-between gap-3"><dt>{{ $row['label'] }}</dt><dd class="font-bold tabular-nums">{{ $row['count'] }}件</dd></div>
                            @endforeach
                        </dl>
                    </div>
                </div>
                @if ($summary['kernels'] !== [])
                    <div class="rounded-xl border border-line bg-paper p-4" data-test="ai-kernels">
                        <h3 class="mb-2 text-note font-bold text-ink-sub">よく出る間違いの型</h3>
                        <ol class="space-y-1">
                            @foreach ($summary['kernels'] as $row)
                                <li class="flex justify-between gap-3"><span class="min-w-0 break-words">{{ $row['label'] }}</span><span class="shrink-0 font-bold tabular-nums">{{ $row['count'] }}回</span></li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            @endif
        </section>

        {{-- AIの実行 --}}
        <section class="space-y-3" data-test="ai-runs">
            <h2 class="text-xl font-bold">AIの実行（{{ number_format($summary['runs']) }}回）</h2>
            @if ($summary['tasks'] === [])
                <p class="text-ink-sub">まだありません</p>
            @else
                <div class="overflow-x-auto rounded-xl border border-line">
                    <table class="w-full whitespace-nowrap text-right tabular-nums">
                        <thead class="bg-panel text-note text-ink-sub">
                            <tr>
                                <th class="px-3 py-2 text-left">作業</th>
                                <th class="px-3 py-2">回数</th>
                                <th class="px-3 py-2">失敗</th>
                                <th class="px-3 py-2">平均</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($summary['tasks'] as $row)
                                <tr>
                                    <td class="px-3 py-2 text-left font-bold">{{ $row['label'] }}</td>
                                    <td class="px-3 py-2">{{ number_format($row['runs']) }}回</td>
                                    <td @class(['px-3 py-2', 'font-bold text-ng' => $row['failed'] > 0])>{{ number_format($row['failed']) }}回</td>
                                    <td class="px-3 py-2">{{ number_format($row['avg_seconds'], 1) }}秒</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <h3 class="pt-2 text-note font-bold text-ink-sub">最近の10回</h3>
                <ul class="divide-y divide-line rounded-xl border border-line bg-paper" data-test="ai-recent">
                    @foreach ($summary['recent'] as $run)
                        <li class="flex flex-wrap items-baseline justify-between gap-x-3 px-3 py-2">
                            <span class="whitespace-nowrap tabular-nums text-ink-sub">{{ $run->created_at?->isoFormat('M月D日(ddd) H:mm') }}</span>
                            <span class="flex items-baseline gap-3 whitespace-nowrap">
                                <span class="font-bold">{{ $run->task->label() }}</span>
                                <span class="tabular-nums">{{ number_format($run->latency_ms / 1000, 1) }}秒</span>
                                @if ($run->valid)
                                    <span class="text-note text-ok">できた</span>
                                @else
                                    <span class="text-note font-bold text-ng">失敗</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- 学習データ --}}
    <section class="space-y-2 rounded-2xl border border-line bg-panel p-5" data-test="ai-export">
        <h2 class="text-xl font-bold">学習データ</h2>
        <p class="text-ink-sub">この月の AIの実行・直した記録・確定した仕訳を、1行1件の JSONL で書き出します。写真・名前・メールアドレスは入れず、人は伏せた記号で出します。</p>
        @can('exportTrainingData', $company)
            <a href="{{ route('company.exports.training', ['company' => $company, 'month' => $start->format('Y-m')]) }}" class="inline-block rounded-lg bg-ink px-4 py-2 font-bold text-paper" data-test="ai-export-link">学習データを書き出す（JSONL）</a>
        @endcan
    </section>
</section>
