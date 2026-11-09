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

    {{-- 初回確定の記録（#32）。測っているのは「下書きに手を入れたか」で、AIの答えの正しさではない --}}
    @php($first = $summary['first'])
    <section class="space-y-3 rounded-2xl border-2 border-ink bg-panel p-5 md:p-6" data-test="ai-first">
        <h2 class="text-xl font-bold">初回確定の記録{{ $first['class'] === 'demo' ? '（デモ・テスト用のデータ）' : '' }}</h2>
        @if ($first['ai_first'] > 0)
            <p class="text-2xl font-bold leading-snug md:text-3xl">
                この月に初めて確定したAIの下書き <span class="tabular-nums" data-test="first-total">{{ number_format($first['ai_first']) }}</span>件のうち、手を入れず確定 <span class="tabular-nums" data-test="first-unchanged">{{ number_format($first['unchanged']) }}</span>件（<span data-test="first-unchanged-pct">{{ $first['unchanged_pct'] }}</span>%）
            </p>
        @else
            <p class="text-2xl font-bold leading-snug md:text-3xl" data-test="first-none">この月に初めて確定したAIの下書きは、まだありません</p>
        @endif
        <p class="text-note text-ink-sub">「手を入れず」は、日付と金額が読めていて、日付・店名・分類・支払い元・金額を変えなかったことです。AIの答えが会計として正しかった割合（正解率）ではありません。摘要は含めません。</p>
        <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-2" data-test="first-details">
            <div class="flex justify-between gap-3"><dt>金額の記載がなく、人が入れた</dt><dd class="font-bold tabular-nums" data-test="first-amount-filled">{{ $first['amount_filled'] }}件{{ $first['amount_filled_pct'] === null ? '' : '（'.$first['amount_filled_pct'].'%）' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>摘要を直した</dt><dd class="font-bold tabular-nums" data-test="first-description">{{ $first['description_changed'] }}件{{ $first['description_changed_pct'] === null ? '' : '（'.$first['description_changed_pct'].'%）' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>支払い元に、直した履歴を使った</dt><dd class="font-bold tabular-nums" data-test="first-history">{{ $first['history_applied'] }}件</dd></div>
            <div class="flex justify-between gap-3"><dt>履歴を使わなかった下書きで、手を入れず確定</dt><dd class="font-bold tabular-nums" data-test="first-without-history">{{ $first['without_history']['unchanged'] }}/{{ $first['without_history']['total'] }}件</dd></div>
            <div class="flex justify-between gap-3"><dt>AIの下書きがなく、人が最初から入力</dt><dd class="font-bold tabular-nums" data-test="first-manual">{{ $first['manual'] }}件{{ $first['manual_pct'] === null ? '' : '（'.$first['manual_pct'].'%）' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>確定のあとで戻した・再確定した</dt><dd class="font-bold tabular-nums" data-test="first-post">{{ $first['post_corrected'] }}件{{ $first['post_corrected_pct'] === null ? '' : '（'.$first['post_corrected_pct'].'%）' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>AIの読み取り・仕訳の失敗</dt><dd class="font-bold tabular-nums" data-test="first-ai-failed">{{ $first['ai_failed'] }}/{{ $first['ai_runs'] }}回</dd></div>
        </dl>
        @php($rec = $first['recommendation'])
        <div class="space-y-1 border-t border-line pt-3" data-test="first-recommendation">
            <h3 class="font-bold">履歴からの候補（同じ店の確定履歴）</h3>
            <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                <div class="flex justify-between gap-3"><dt>候補があった初回確定</dt><dd class="font-bold tabular-nums" data-test="rec-with-candidate">{{ $rec['with_candidate'] }}件</dd></div>
                <div class="flex justify-between gap-3"><dt>候補どおりにした</dt><dd class="font-bold tabular-nums" data-test="rec-adopted">{{ $rec['adopted'] }}件{{ $rec['adopted_pct'] === null ? '' : '（'.$rec['adopted_pct'].'%）' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>別を選んだ</dt><dd class="font-bold tabular-nums" data-test="rec-rejected">{{ $rec['rejected'] }}件{{ $rec['rejected_pct'] === null ? '' : '（'.$rec['rejected_pct'].'%）' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>保留にした</dt><dd class="font-bold tabular-nums" data-test="rec-held">{{ $rec['held'] }}件{{ $rec['held_pct'] === null ? '' : '（'.$rec['held_pct'].'%）' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>候補どおりにしたあとで戻した・再確定した</dt><dd class="font-bold tabular-nums" data-test="rec-corrected">{{ $rec['adopted_then_corrected'] }}件</dd></div>
                <div class="flex justify-between gap-3"><dt>下書きの候補の根拠が、あとで変わった</dt><dd class="font-bold tabular-nums" data-test="rec-expired">{{ $rec['expired'] }}件</dd></div>
                <div class="flex justify-between gap-3"><dt>候補がなかった（失敗ではありません）</dt><dd class="font-bold tabular-nums" data-test="rec-none">{{ $rec['no_candidate'] }}件</dd></div>
            </dl>
            <p class="text-note text-ink-sub" data-test="rec-note">候補どおりにした率だけでは、候補が良かったとは言えません。確認にかかった時間はまだ測っていません（実作業の計測は #44）。</p>
        </div>
        @if ($first['legacy'] > 0)
            <p class="text-note text-ink-sub" data-test="first-legacy">この機能より前に確定した {{ $first['legacy'] }}件は、初回確定の記録がないため、この率に入れていません（評価不能）。</p>
        @endif
        @if ($first['other_class'] > 0)
            <p class="text-note text-ink-sub" data-test="first-other-class">{{ $first['class'] === 'demo' ? '実際のAIの' : 'デモ・テスト用（fake・再生・デモ会社）の' }}{{ $first['other_class'] }}件は、数字が混ざらないよう別にしています。</p>
        @endif
    </section>

    {{-- 従来の数え方（確定した月・直した記録の有無）。再確定や過去の記録を含むため、上の記録と一致しないことがある --}}
    <section class="space-y-3 rounded-2xl border border-line bg-panel p-5 md:p-6" data-test="ai-headline">
        <h2 class="text-note font-bold text-ink-sub">従来の数え方（参考）</h2>
        @if ($summary['ai_entries'] > 0)
            <p class="text-xl font-bold leading-snug">
                AIが作った仕訳 <span class="tabular-nums" data-test="ai-entries">{{ number_format($summary['ai_entries']) }}</span>件のうち、そのまま確定 <span class="tabular-nums" data-test="ai-unchanged">{{ number_format($summary['unchanged']) }}</span>件（<span data-test="ai-unchanged-pct">{{ $summary['unchanged_pct'] }}</span>%）
            </p>
        @else
            <p class="text-xl font-bold leading-snug">この月は、AIが作って確定した仕訳がまだありません</p>
        @endif
        @if ($summary['top_reason'] !== null)
            <p class="font-bold" data-test="ai-top-reason">一番多い直し：{{ $summary['top_reason']['label'] }}（{{ $summary['top_reason']['count'] }}件）</p>
        @else
            <p class="text-ink-sub">この月の直しはまだありません</p>
        @endif
        <p class="text-note text-ink-sub">確定した月と、直した記録の有無で数えます。再確定した仕訳も含みます。直した記録は、次の Python 編で規則にする材料になります。</p>
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
                                @if ($run->driver === 'replay')
                                    <span class="text-note font-bold text-ink-sub">再生</span>
                                @elseif ($run->valid)
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
        <p class="text-ink-sub">この月の AIの実行・直した記録・確定した仕訳を、1行1件の JSONL（と manifest.json）で書き出します。決めた項目の数字・日付・種類だけを出し、写真・OCRの全文・摘要・理由・名前・メールアドレスは入れません。店・分類・人は会社ごとの伏せた記号です。</p>
        <p class="text-ink-sub">伏せただけで、匿名ではありません。受け取ったファイルは社外に出さず、公開の場所（GitHub など）に上げないでください。</p>
        @can('exportTrainingData', $company)
            <a href="{{ route('company.exports.training', ['company' => $company, 'month' => $start->format('Y-m')]) }}" class="inline-block rounded-lg bg-ink px-4 py-2 font-bold text-paper" data-test="ai-export-link">学習データを書き出す（JSONL）</a>
        @endcan
    </section>
</section>
