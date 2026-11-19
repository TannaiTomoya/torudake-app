<?php

use App\Enums\AccountType;
use App\Enums\Side;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\OpeningEntryService;
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

    /** @var list<array{account_id: string, side: string, amount: string}> */
    public array $lines = [];

    public string $notice = '';

    public function mount(Company $company): void
    {
        $this->authorize('enterOpeningEntry', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->lines = $this->savedLines();
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        $used = $this->opening?->lines()->pluck('account_id')->all() ?? [];

        return $this->company->accounts()
            ->whereIn('type', array_map(fn (AccountType $type): string => $type->value, OpeningEntryService::BALANCE_SHEET_TYPES))
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhereIn('id', $used))
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function opening(): ?JournalEntry
    {
        return $this->company->openingEntries()->first();
    }

    public function openingDate(): CarbonImmutable
    {
        $saved = $this->opening;

        return $saved !== null ? CarbonImmutable::instance($saved->entry_date) : app(OpeningEntryService::class)->openingDate($this->company);
    }

    public function addLine(): void
    {
        $this->authorize('enterOpeningEntry', $this->company);

        if (count($this->lines) < OpeningEntryService::MAX_LINES) {
            $this->lines[] = ['account_id' => '', 'side' => Side::Debit->value, 'amount' => ''];
        }
    }

    public function removeLine(int $index): void
    {
        $this->authorize('enterOpeningEntry', $this->company);

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function save(OpeningEntryService $service): void
    {
        $this->authorize('enterOpeningEntry', $this->company);
        $this->notice = '';

        $this->lines = array_values(array_filter(
            array_map(fn (array $line): array => [
                'account_id' => (string) ($line['account_id'] ?? ''),
                'side' => (string) ($line['side'] ?? ''),
                'amount' => str_replace([',', '，', ' ', '円'], '', mb_convert_kana((string) ($line['amount'] ?? ''), 'n')),
            ], $this->lines),
            fn (array $line): bool => $line['account_id'] !== '' || $line['amount'] !== '',
        ));

        $this->validate([
            'lines' => ['required', 'array', 'min:1', 'max:'.OpeningEntryService::MAX_LINES],
            'lines.*.account_id' => ['required', 'string'],
            'lines.*.side' => ['required', 'in:debit,credit'],
            'lines.*.amount' => ['required', 'regex:/^\d{1,12}$/'],
        ], [
            'lines.required' => '行を1つ以上入れてください。',
            'lines.*.account_id.required' => '科目を選んでください。',
            'lines.*.side.*' => '借方か貸方を選んでください。',
            'lines.*.amount.*' => '金額は1円以上の数字で入れてください。',
        ]);

        /** @var User $user */
        $user = Auth::user();

        $service->save($this->company, $user, array_map(fn (array $line): array => [
            'account_id' => $line['account_id'],
            'side' => $line['side'],
            'amount' => (int) $line['amount'],
        ], $this->lines));

        unset($this->opening);
        $this->lines = $this->savedLines();
        $this->notice = '開始仕訳を保存しました。';
    }

    /**
     * @return array{debit: int, credit: int}
     */
    public function totals(): array
    {
        $totals = ['debit' => 0, 'credit' => 0];
        foreach ($this->lines as $line) {
            $amount = (int) str_replace([',', '円'], '', (string) ($line['amount'] ?? '0'));
            $totals[($line['side'] ?? '') === Side::Credit->value ? 'credit' : 'debit'] += max(0, $amount);
        }

        return $totals;
    }

    /**
     * @return list<array{account_id: string, side: string, amount: string}>
     */
    private function savedLines(): array
    {
        $saved = $this->company->openingEntries()->first();
        if ($saved === null) {
            return [
                ['account_id' => '', 'side' => Side::Debit->value, 'amount' => ''],
                ['account_id' => '', 'side' => Side::Credit->value, 'amount' => ''],
            ];
        }

        return $saved->lines()->orderBy('line_no')->get()
            ->map(fn (JournalLine $line): array => ['account_id' => $line->account_id, 'side' => $line->side->value, 'amount' => (string) $line->amount_yen])
            ->values()->all();
    }
}; ?>

<section class="mx-auto max-w-4xl space-y-6 p-4 text-body text-ink">
    <header class="border-b border-line pb-4">
        <h1 class="text-2xl font-bold text-ink">開始仕訳（期首の残高）</h1>
        <p class="mt-1 text-note text-ink-sub">前期の決算書（貸借対照表）の資産・負債・純資産の残高を、{{ $this->openingDate()->year }}年{{ $this->openingDate()->isoFormat('M月D日(ddd)') }}の日付で1回だけ入れます。あとから直すと、前後の値が記録に残ります。</p>
    </header>

    @if ($notice !== '')
        <p role="status" class="rounded-lg bg-ok-soft px-4 py-3 font-bold text-ok" data-test="opening-notice">{{ $notice }}</p>
    @endif
    @if ($this->opening !== null)
        <p class="text-note text-ink-sub" data-test="opening-saved">入れ済みです（{{ $this->opening->confirmed_at?->isoFormat('M月D日(ddd)') }}）。</p>
    @endif
    @error('lines') <p role="alert" class="rounded-lg bg-ng-soft px-4 py-3 font-bold text-ng">{{ $message }}</p> @enderror

    <form wire:submit="save" class="space-y-4 rounded-xl border border-line bg-panel p-5" data-test="opening-form">
        <ul class="space-y-3">
            @foreach ($lines as $index => $line)
                <li wire:key="opening-line-{{ $index }}" class="flex flex-wrap items-end gap-3">
                    <label class="flex-1 font-bold text-ink">
                        <span class="block text-note">科目</span>
                        <select wire:model="lines.{{ $index }}.account_id" class="mt-1 w-full rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink">
                            <option value="">選んでください</option>
                            @foreach ($this->accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                            @endforeach
                        </select>
                        @error("lines.{$index}.account_id") <span class="block text-note text-ng">{{ $message }}</span> @enderror
                    </label>
                    <label class="font-bold text-ink">
                        <span class="block text-note">借方／貸方</span>
                        <select wire:model.live="lines.{{ $index }}.side" class="mt-1 rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink">
                            <option value="debit">借方</option>
                            <option value="credit">貸方</option>
                        </select>
                    </label>
                    <label class="font-bold text-ink">
                        <span class="block text-note">金額</span>
                        <span class="mt-1 flex items-center gap-2">
                            <input type="text" inputmode="numeric" wire:model.blur="lines.{{ $index }}.amount" class="w-44 rounded-lg border border-line bg-paper px-3 py-2 text-right text-body text-ink" />
                            <span class="text-body">円</span>
                        </span>
                        @error("lines.{$index}.amount") <span class="block text-note text-ng">{{ $message }}</span> @enderror
                    </label>
                    <button type="button" wire:click="removeLine({{ $index }})" class="rounded-lg border border-line px-3 py-2 text-note font-bold text-ink">外す</button>
                </li>
            @endforeach
        </ul>

        @php($totals = $this->totals())
        <p @class(['font-bold', 'text-ok' => $totals['debit'] === $totals['credit'] && $totals['debit'] > 0, 'text-ng' => $totals['debit'] !== $totals['credit']]) data-test="opening-totals">
            借方 {{ number_format($totals['debit']) }}円・貸方 {{ number_format($totals['credit']) }}円
        </p>

        <div class="flex flex-wrap gap-3">
            <button type="button" wire:click="addLine" class="rounded-lg border border-line bg-paper px-4 py-2 font-bold text-ink">行を足す</button>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-ink px-4 py-2 font-bold text-paper disabled:opacity-60">保存する</button>
            <a href="{{ route('company.trial-balance', $company) }}" wire:navigate class="rounded-lg px-4 py-2 font-bold text-ink underline">試算表へ</a>
        </div>
    </form>
</section>
