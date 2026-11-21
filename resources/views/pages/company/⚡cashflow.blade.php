<?php

use App\Models\Company;
use App\Services\CashFlowBoard;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public string $bankAccountId = '';

    public function mount(Company $company): void
    {
        $this->authorize('viewCashFlow', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->bankAccountId = app(CashFlowBoard::class)->accounts($company)->first()->id ?? '';
    }

    public function hydrate(): void
    {
        $this->authorize('viewCashFlow', $this->company);
    }

    public function updatedBankAccountId(): void
    {
        $this->authorize('viewCashFlow', $this->company);
        unset($this->forecast);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    #[Computed]
    public function accounts(): array
    {
        return app(CashFlowBoard::class)->accounts($this->company)
            ->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     account_id: string,
     *     account_name: string,
     *     missing_balance: bool,
     *     balance_date: string|null,
     *     opening_balance_yen: int|null,
     *     from: string,
     *     to: string,
     *     forecast: array|null
     * }|null
     */
    #[Computed]
    public function forecast(): ?array
    {
        if ($this->bankAccountId === '') {
            return null;
        }

        return app(CashFlowBoard::class)->forAccount($this->company, $this->bankAccountId);
    }

    public function yen(int $amount): string
    {
        return number_format($amount).'円';
    }

    public function monthLabel(string $yearMonth): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $yearMonth)->isoFormat('Y年M月');
    }
}; ?>

<section class="mx-auto max-w-5xl space-y-8 p-4 text-body text-ink" data-test="cashflow-page">
    <header class="border-b border-line pb-4">
        <h1 class="text-2xl font-bold text-ink">資金繰り表</h1>
        <p class="mt-1 text-note text-ink-sub">今の残高に、まだ済んでいない入金と支払いの予定を日ごとに足し引きします。予定日は動かしません。土日には「休日」の印を付けます。</p>
    </header>

    @if ($this->accounts === [])
        <p class="rounded-lg border border-line bg-panel px-4 py-3 text-ink-sub" data-test="cashflow-no-account">銀行の口座がありません。会社設定で口座を足してください。</p>
    @else
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label for="cashflow-account" class="block text-note text-ink-sub">口座</label>
                <select id="cashflow-account" wire:model.live="bankAccountId" class="mt-1 min-w-56 rounded-lg border border-line bg-paper px-3 py-2 text-ink" data-test="cashflow-account">
                    @foreach ($this->accounts as $account)
                        <option value="{{ $account['id'] }}">{{ $account['name'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @php($row = $this->forecast)
        @if ($row === null)
            <p class="text-ink-sub">口座を選んでください。</p>
        @elseif ($row['missing_balance'])
            <p role="status" class="rounded-lg border border-warn bg-warn-soft px-4 py-3 font-bold text-warn" data-test="cashflow-missing-balance">
                残高が未入力です。見込みは出しません（0円とはみなしません）。
                <a href="{{ route('company.balances', $company) }}" wire:navigate class="ml-2 underline">通帳の残高を入れる</a>
            </p>
        @else
            @php($forecast = $row['forecast'])
            <p class="text-note text-ink-sub" data-test="cashflow-range">
                起点：{{ CarbonImmutable::parse($row['balance_date'])->isoFormat('M月D日(ddd)') }}の残高 {{ $this->yen($row['opening_balance_yen']) }}
                ／ 見込み：{{ CarbonImmutable::parse($row['from'])->isoFormat('M月D日') }}〜{{ CarbonImmutable::parse($row['to'])->isoFormat('M月D日') }}
            </p>

            @if ($forecast['month_end_weekend_months'] !== [])
                <div class="space-y-2" data-test="cashflow-month-end-weekend">
                    @foreach ($forecast['month_end_weekend_months'] as $month)
                        <p role="status" class="rounded-lg border border-line bg-panel px-4 py-3 text-ink">
                            {{ $this->monthLabel($month) }}の月末が休日です。入金が翌月になる取引がないか確かめてください。
                        </p>
                    @endforeach
                </div>
            @endif

            @if ($forecast['below_zero_dates'] !== [])
                <p role="status" class="rounded-lg border border-ng bg-ng-soft px-4 py-3 font-bold text-ng" data-test="cashflow-below-zero-summary">
                    残高が0円を下回る見込みの日が {{ count($forecast['below_zero_dates']) }}日あります。
                </p>
            @endif

            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="min-w-full text-left" data-test="cashflow-table">
                    <thead class="bg-panel text-note text-ink-sub">
                        <tr>
                            <th class="px-3 py-2 font-bold">日付</th>
                            <th class="px-3 py-2 font-bold text-right">入金</th>
                            <th class="px-3 py-2 font-bold text-right">支払い</th>
                            <th class="px-3 py-2 font-bold text-right">残高の見込み</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($forecast['days'] as $day)
                            <tr
                                wire:key="cashflow-{{ $day['date'] }}"
                                @class([
                                    'border-t border-line',
                                    'bg-ng-soft text-ng' => $day['below_zero'],
                                ])
                                data-test="cashflow-day"
                                data-date="{{ $day['date'] }}"
                                @if ($day['weekend']) data-weekend="1" @endif
                                @if ($day['below_zero']) data-below-zero="1" @endif
                            >
                                <td class="px-3 py-2">
                                    <span class="font-bold tabular-nums">{{ CarbonImmutable::parse($day['date'])->isoFormat('M月D日(ddd)') }}</span>
                                    @if ($day['weekend'])
                                        <span class="ml-2 text-note text-ink-sub" data-test="cashflow-weekend-mark">休日</span>
                                    @endif
                                    @if ($day['below_zero'])
                                        <span class="ml-2 text-note font-bold" data-test="cashflow-below-zero-mark">残高が0円を下回る見込み</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $day['in_yen'] > 0 ? $this->yen($day['in_yen']) : '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $day['out_yen'] > 0 ? $this->yen($day['out_yen']) : '—' }}</td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums">{{ $this->yen($day['balance_yen']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</section>
