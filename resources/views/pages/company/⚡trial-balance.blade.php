<?php

use App\Enums\AccountType;
use App\Models\Company;
use App\Services\TrialBalanceReport;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public string $month = '';

    public function mount(Company $company): void
    {
        $this->authorize('viewTrialBalance', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->month = CarbonImmutable::today()->format('Y-m');
    }

    public function updatedMonth(): void
    {
        $this->authorize('viewTrialBalance', $this->company);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = CarbonImmutable::today()->format('Y-m');
        }
        unset($this->result);
    }

    /**
     * @return array{month: string, fiscal_year_start: string, has_opening: bool, rows: list<array{account_id: string, code: string, name: string, type: string, carried: int, debit: int, credit: int, closing: int}>, debit_total: int, credit_total: int, balanced: bool, problem: string|null}
     */
    #[Computed]
    public function result(): array
    {
        $this->authorize('viewTrialBalance', $this->company);

        return app(TrialBalanceReport::class)->forMonth($this->company, $this->month);
    }

    public function yen(int $amount): string
    {
        return number_format($amount).'円';
    }
}; ?>

<section class="mx-auto max-w-5xl space-y-6 p-4 text-body text-ink">
    <header class="flex flex-wrap items-end justify-between gap-4 border-b border-line pb-4">
        <div>
            <h1 class="text-2xl font-bold text-ink">試算表</h1>
            <p class="mt-1 text-note text-ink-sub">確定した仕訳を、勘定科目ごとに足し上げます（アプリに登録した仕訳の分）。</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <label for="trial-month" class="font-bold text-ink">
                <span class="block text-note">月</span>
                <input id="trial-month" type="month" wire:model.live="month" class="mt-1 rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink" />
            </label>
            <a href="{{ route('company.trial-balance.xlsx', [$company, $month]) }}" class="rounded-lg border border-line bg-paper px-4 py-2 font-bold text-ink" data-test="trial-balance-xlsx">Excel</a>
            @can('enterOpeningEntry', $company)
                <a href="{{ route('company.opening', $company) }}" wire:navigate class="rounded-lg border border-line bg-paper px-4 py-2 font-bold text-ink" data-test="trial-balance-opening">開始仕訳</a>
            @endcan
        </div>
    </header>

    @php($result = $this->result)

    @if (! $result['has_opening'])
        <p class="rounded-lg bg-warn-soft px-4 py-3 font-bold text-warn" data-test="trial-balance-no-opening">{{ \App\Services\TrialBalanceReport::NO_OPENING_NOTE }}</p>
    @endif

    @if ($result['balanced'])
        <p role="status" class="rounded-lg bg-ok-soft px-4 py-3 font-bold text-ok" data-test="trial-balance-status">借方と貸方は合っています（{{ $this->yen($result['debit_total']) }}）</p>
    @else
        <p role="alert" class="rounded-lg bg-ng-soft px-4 py-3 font-bold text-ng" data-test="trial-balance-status">合っていません（借方 {{ $this->yen($result['debit_total']) }}・貸方 {{ $this->yen($result['credit_total']) }}）</p>
    @endif

    @if ($result['rows'] === [])
        <p class="rounded-xl border border-line bg-panel p-5 text-ink-sub">この月までの確定した仕訳はありません。</p>
    @else
        <div class="overflow-x-auto rounded-xl border border-line bg-panel">
            <table class="w-full text-body" data-test="trial-balance-table">
                <thead class="border-b border-line text-left text-note text-ink-sub">
                    <tr>
                        <th class="px-4 py-2">科目</th>
                        <th class="px-4 py-2 text-right">前月まで</th>
                        <th class="px-4 py-2 text-right">借方</th>
                        <th class="px-4 py-2 text-right">貸方</th>
                        <th class="px-4 py-2 text-right">月末の残高</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($result['rows'] as $row)
                        <tr wire:key="tb-{{ $row['account_id'] }}">
                            <td class="px-4 py-2">
                                <span class="font-bold text-ink">{{ $row['name'] }}</span>
                                <span class="ml-1 text-note text-ink-sub">{{ $row['code'] }}・{{ AccountType::from($row['type'])->label() }}</span>
                            </td>
                            <td @class(['px-4 py-2 text-right tabular-nums', 'text-ng' => $row['carried'] < 0])>{{ $this->yen($row['carried']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $this->yen($row['debit']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $this->yen($row['credit']) }}</td>
                            <td @class(['px-4 py-2 text-right font-bold tabular-nums', 'text-ng' => $row['closing'] < 0])>{{ $this->yen($row['closing']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-line font-bold">
                    <tr>
                        <td class="px-4 py-2">合計（この月）</td>
                        <td></td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $this->yen($result['debit_total']) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $this->yen($result['credit_total']) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    <p class="text-note text-ink-sub">借方・貸方はこの月の動き。前月までは、資産・負債・純資産は使い始めから（開始仕訳を含む）、収益・費用はこの期（{{ CarbonImmutable::parse($result['fiscal_year_start'])->year }}年{{ CarbonImmutable::parse($result['fiscal_year_start'])->isoFormat('M月D日(ddd)') }}から）の分です。</p>
</section>
