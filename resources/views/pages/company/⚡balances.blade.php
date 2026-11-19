<?php

use App\Enums\BankAccountKind;
use App\Models\BankAccount;
use App\Models\BankBalance;
use App\Models\Company;
use App\Models\User;
use App\Services\BankBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public string $bankAccountId = '';

    public string $balanceDate = '';

    public string $balanceYen = '';

    public string $notice = '';

    public function mount(Company $company): void
    {
        $this->authorize('enterBankBalance', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->balanceDate = CarbonImmutable::today()->toDateString();
        $this->bankAccountId = $this->accounts->first()->id ?? '';
    }

    /**
     * @return Collection<int, BankAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return $this->company->bankAccounts()
            ->where('kind', BankAccountKind::Bank->value)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, BankBalance>
     */
    #[Computed]
    public function balances(): Collection
    {
        return $this->company->bankBalances()
            ->with('bankAccount')
            ->orderByDesc('balance_date')
            ->orderBy('bank_account_id')
            ->limit(60)
            ->get();
    }

    public function save(BankBalanceService $service): void
    {
        $this->authorize('enterBankBalance', $this->company);
        $this->notice = '';
        $this->balanceYen = str_replace([',', '，', ' ', '円'], '', mb_convert_kana($this->balanceYen, 'n'));
        $this->balanceYen = str_replace(['ー', '－', '−', '△', '▲'], '-', $this->balanceYen);

        $this->validate([
            'bankAccountId' => ['required', 'string'],
            'balanceDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'balanceYen' => ['required', 'regex:/^-?\d{1,13}$/'],
        ], [
            'bankAccountId.required' => '口座を選んでください。',
            'balanceDate.*' => '日付を正しく入れてください。',
            'balanceYen.*' => '残高は円の数字で入れてください（マイナスは先頭に -）。',
        ]);

        /** @var User $user */
        $user = Auth::user();

        $balance = $service->enter($this->company, $user, $this->bankAccountId, CarbonImmutable::parse($this->balanceDate), (int) $this->balanceYen);
        unset($this->balances);

        $this->notice = $balance->balance_date->isoFormat('M月D日(ddd)').'の残高を保存しました。';
        $this->balanceYen = '';
    }

    public function remove(string $balanceId, BankBalanceService $service): void
    {
        $this->authorize('enterBankBalance', $this->company);

        /** @var User $user */
        $user = Auth::user();

        if (! $this->company->bankBalances()->whereKey($balanceId)->exists()) {
            $this->notice = 'この残高はすでに取り消されています。';

            return;
        }

        $service->remove($this->company, $user, $balanceId);
        unset($this->balances);
        $this->notice = '残高を取り消しました。';
    }
}; ?>

<section class="mx-auto max-w-4xl space-y-8 p-4 text-body text-ink">
    <header class="border-b border-line pb-4">
        <h1 class="text-2xl font-bold text-ink">通帳の残高</h1>
        <p class="mt-1 text-note text-ink-sub">通帳や明細に書いてある、その日の終わりの残高を入れます。同じ口座・同じ日にもう一度入れると上書きします。</p>
    </header>

    @if ($notice !== '')
        <p role="status" class="rounded-lg bg-ok-soft px-4 py-3 font-bold text-ok" data-test="balance-notice">{{ $notice }}</p>
    @endif

    @if ($this->accounts->isEmpty())
        <p class="rounded-xl border border-line bg-panel p-5 text-ink-sub">銀行の口座がまだありません。</p>
    @else
        <form wire:submit="save" class="space-y-4 rounded-xl border border-line bg-panel p-5" data-test="balance-form">
            <div>
                <label for="balance-account" class="block font-bold text-ink">口座</label>
                <select id="balance-account" wire:model="bankAccountId" class="mt-1 w-full rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink">
                    @foreach ($this->accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
                @error('bankAccountId') <p class="text-note text-ng">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="balance-date" class="block font-bold text-ink">日付</label>
                <input id="balance-date" type="date" wire:model="balanceDate" class="mt-1 w-48 rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink" />
                @error('balanceDate') <p class="text-note text-ng">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="balance-yen" class="block font-bold text-ink">残高</label>
                <div class="mt-1 flex items-center gap-2">
                    <input id="balance-yen" type="text" inputmode="numeric" wire:model="balanceYen" placeholder="1234567" class="w-56 rounded-lg border border-line bg-paper px-3 py-2 text-right text-body text-ink" />
                    <span class="text-body text-ink">円</span>
                </div>
                <p class="mt-1 text-note text-ink-sub">借り越しているときは、先頭に - か △ を付けます。</p>
                @error('balanceYen') <p class="text-note text-ng">{{ $message }}</p> @enderror
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-ink px-4 py-2 font-bold text-paper disabled:opacity-60">保存する</button>
        </form>
    @endif

    <div class="space-y-3 rounded-xl border border-line bg-panel p-5">
        <h2 class="text-lg font-bold text-ink">入れた残高</h2>
        @if ($this->balances->isEmpty())
            <p class="text-ink-sub">まだありません。</p>
        @else
            <ul class="divide-y divide-line" data-test="balance-list">
                @foreach ($this->balances as $balance)
                    <li wire:key="balance-{{ $balance->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <span class="font-bold text-ink">{{ $balance->balance_date->isoFormat('M月D日(ddd)') }}</span>
                            <span class="ml-2 text-ink-sub">{{ $balance->bankAccount->name }}</span>
                        </div>
                        <div class="flex items-center gap-4">
                            <span @class(['font-bold tabular-nums', 'text-ng' => $balance->balance_yen < 0, 'text-ink' => $balance->balance_yen >= 0])>{{ number_format($balance->balance_yen) }}円</span>
                            <button type="button" wire:click="remove('{{ $balance->id }}')" wire:confirm="この残高を取り消しますか？" class="rounded-lg border border-line px-3 py-1 text-note font-bold text-ink">取り消す</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
