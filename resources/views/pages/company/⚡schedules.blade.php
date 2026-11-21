<?php

use App\Enums\BankAccountKind;
use App\Enums\ScheduleCategory;
use App\Enums\ScheduleDirection;
use App\Models\BankAccount;
use App\Models\BusinessPartner;
use App\Models\CashSchedule;
use App\Models\Company;
use App\Models\User;
use App\Services\CashScheduleBoard;
use App\Services\CashScheduleInput;
use App\Services\CashScheduleService;
use App\Support\ScheduleDelay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public string $direction = 'in';

    public string $category = 'sales';

    public string $partnerId = '';

    public string $bankAccountId = '';

    public string $amountYen = '';

    public string $dueDate = '';

    #[Locked]
    public ?string $editingId = null;

    public string $settleDate = '';

    public string $partnerName = '';

    #[Locked]
    public ?string $renamingPartnerId = null;

    public string $renameName = '';

    public string $notice = '';

    public function mount(Company $company): void
    {
        $this->authorize('manageSchedules', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->settleDate = CarbonImmutable::today()->toDateString();
        $this->bankAccountId = $this->accounts->first()->id ?? '';
    }

    /**
     * 開いたあとの通信（欄の変更・読み直しも含む）のたびに確かめる。取引先の名前と金額を、権限を失った人に描き直さないため。
     */
    public function hydrate(): void
    {
        $this->authorize('manageSchedules', $this->company);
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
     * @return Collection<int, BusinessPartner>
     */
    #[Computed]
    public function partners(): Collection
    {
        return $this->company->businessPartners()->whereNull('archived_at')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, CashSchedule>
     */
    #[Computed]
    public function openSchedules(): Collection
    {
        return app(CashScheduleBoard::class)->open($this->company);
    }

    /**
     * @return Collection<int, CashSchedule>
     */
    #[Computed]
    public function settledSchedules(): Collection
    {
        return app(CashScheduleBoard::class)->settled($this->company);
    }

    /**
     * @return array{in: array{open_count: int, open_yen: int, late_count: int, late_yen: int}, out: array{open_count: int, open_yen: int, late_count: int, late_yen: int}}
     */
    #[Computed]
    public function summary(): array
    {
        return app(CashScheduleBoard::class)->summary($this->company);
    }

    /**
     * @return list<ScheduleCategory>
     */
    public function categories(): array
    {
        return ScheduleCategory::forDirection(ScheduleDirection::tryFrom($this->direction) ?? ScheduleDirection::In);
    }

    public function partnerAllowed(): bool
    {
        return ScheduleCategory::tryFrom($this->category)?->allowsPartner() ?? true;
    }

    public function updatedDirection(): void
    {
        $this->category = $this->categories()[0]->value;
        $this->partnerId = '';
    }

    public function updatedCategory(): void
    {
        if (! $this->partnerAllowed()) {
            $this->partnerId = '';
        }
    }

    public function save(CashScheduleService $service): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->notice = '';
        $this->amountYen = str_replace([',', '，', ' ', '　', '円'], '', mb_convert_kana($this->amountYen, 'n'));

        $this->validate([
            'direction' => ['required', Rule::enum(ScheduleDirection::class)],
            'category' => ['required', Rule::enum(ScheduleCategory::class)],
            'partnerId' => ['nullable', 'string'],
            'bankAccountId' => ['required', 'string'],
            'amountYen' => ['required', 'regex:/\A\d{1,13}\z/'],
            'dueDate' => ['required', 'date_format:Y-m-d'],
        ], [
            'direction.*' => '入金か支払いかを選んでください。',
            'category.*' => '種類を選んでください。',
            'bankAccountId.required' => '口座を選んでください。',
            'amountYen.*' => '金額は円の数字で入れてください。',
            'dueDate.*' => '予定日を正しく入れてください。',
        ]);

        /** @var User $user */
        $user = Auth::user();

        $input = new CashScheduleInput(
            direction: ScheduleDirection::from($this->direction),
            category: ScheduleCategory::from($this->category),
            partnerId: $this->partnerId === '' ? null : $this->partnerId,
            bankAccountId: $this->bankAccountId,
            amountYen: (int) $this->amountYen,
            dueDate: CarbonImmutable::parse($this->dueDate),
        );

        if ($this->editingId !== null) {
            $schedule = $service->update($this->company, $user, $this->editingId, $input);
            $this->notice = $schedule->due_date->isoFormat('M月D日(ddd)').'の予定を直しました。';
        } else {
            $schedule = $service->add($this->company, $user, $input);
            $this->notice = $schedule->due_date->isoFormat('M月D日(ddd)').'の'.$schedule->direction->label().'の予定を入れました。';
        }

        $this->resetForm();
        $this->refreshLists();
    }

    public function startEdit(string $scheduleId): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->resetErrorBag();
        $this->notice = '';

        /** @var CashSchedule $schedule */
        $schedule = $this->company->cashSchedules()->whereNull('settled_on')->findOrFail($scheduleId);

        $this->editingId = $schedule->id;
        $this->direction = $schedule->direction->value;
        $this->category = $schedule->category->value;
        $this->partnerId = $schedule->business_partner_id ?? '';
        $this->bankAccountId = $schedule->bank_account_id;
        $this->amountYen = (string) $schedule->amount_yen;
        $this->dueDate = $schedule->due_date->toDateString();
    }

    public function cancelEdit(): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->resetErrorBag();
        $this->resetForm();
    }

    public function settle(string $scheduleId, CashScheduleService $service): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->notice = '';
        $this->resetErrorBag();

        $this->validate(
            ['settleDate' => ['required', 'date_format:Y-m-d']],
            ['settleDate.*' => '済みの日を正しく入れてください。'],
        );

        /** @var User $user */
        $user = Auth::user();

        $schedule = $service->settle($this->company, $user, $scheduleId, CarbonImmutable::parse($this->settleDate));
        if ($this->editingId === $schedule->id) {
            $this->resetForm();
        }
        $this->refreshLists();
        $this->notice = $schedule->direction->settledLabel().'にしました。';
    }

    public function unsettle(string $scheduleId, CashScheduleService $service): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->notice = '';
        $this->resetErrorBag();

        /** @var User $user */
        $user = Auth::user();

        $service->unsettle($this->company, $user, $scheduleId);
        $this->refreshLists();
        $this->notice = '済みを戻しました。';
    }

    public function remove(string $scheduleId, CashScheduleService $service): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->notice = '';
        $this->resetErrorBag();

        /** @var User $user */
        $user = Auth::user();

        if (! $this->company->cashSchedules()->whereKey($scheduleId)->exists()) {
            $this->notice = 'この予定はすでに取り消されています。';

            return;
        }

        $service->remove($this->company, $user, $scheduleId);
        if ($this->editingId === $scheduleId) {
            $this->resetForm();
        }
        $this->refreshLists();
        $this->notice = '予定を取り消しました。';
    }

    public function addPartner(CashScheduleService $service): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->notice = '';
        $this->resetErrorBag();

        /** @var User $user */
        $user = Auth::user();

        $partner = $service->addPartner($this->company, $user, $this->partnerName);
        $this->partnerName = '';
        unset($this->partners);
        if ($this->partnerAllowed()) {
            $this->partnerId = $partner->id;
        }
        $this->notice = '取引先を足しました。';
    }

    public function startRename(string $partnerId): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->resetErrorBag();

        /** @var BusinessPartner $partner */
        $partner = $this->company->businessPartners()->whereNull('archived_at')->findOrFail($partnerId);

        $this->renamingPartnerId = $partner->id;
        $this->renameName = $partner->name;
    }

    public function cancelRename(): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->resetErrorBag();
        $this->renamingPartnerId = null;
        $this->renameName = '';
    }

    public function saveRename(CashScheduleService $service): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->notice = '';
        $this->resetErrorBag();

        if ($this->renamingPartnerId === null) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();

        $service->renamePartner($this->company, $user, $this->renamingPartnerId, $this->renameName);
        $this->renamingPartnerId = null;
        $this->renameName = '';
        $this->refreshLists();
        $this->notice = '取引先の名前を直しました。';
    }

    public function archivePartner(string $partnerId, CashScheduleService $service): void
    {
        $this->authorize('manageSchedules', $this->company);
        $this->notice = '';
        $this->resetErrorBag();

        /** @var User $user */
        $user = Auth::user();

        $service->archivePartner($this->company, $user, $partnerId);
        if ($this->partnerId === $partnerId && $this->editingId === null) {
            $this->partnerId = '';
        }
        if ($this->renamingPartnerId === $partnerId) {
            $this->renamingPartnerId = null;
            $this->renameName = '';
        }
        $this->refreshLists();
        $this->notice = '取引先を使わなくしました。入れてある予定はそのまま残ります。';
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->direction = ScheduleDirection::In->value;
        $this->category = ScheduleCategory::Sales->value;
        $this->partnerId = '';
        $this->amountYen = '';
        $this->dueDate = '';
        $this->bankAccountId = $this->accounts->first()->id ?? '';
    }

    private function refreshLists(): void
    {
        unset($this->openSchedules, $this->settledSchedules, $this->summary, $this->partners);
    }
}; ?>

@php
    $summary = $this->summary;
    $partnerAllowed = $this->partnerAllowed();
    $isIn = $direction === ScheduleDirection::In->value;
    $today = CarbonImmutable::today()->toDateString();
    $openTotal = $summary['in']['open_count'] + $summary['out']['open_count'];
    // 直している予定の取引先が「使わなくした取引先」のときも、選んだままにできるようにする（会社から引く）
    $archivedPartner = $partnerId !== '' && ! $this->partners->contains('id', $partnerId)
        ? $this->company->businessPartners()->whereNotNull('archived_at')->find($partnerId)
        : null;
@endphp

<section class="mx-auto max-w-4xl space-y-8 p-4 text-body text-ink">
    <header class="border-b border-line pb-4">
        <h1 class="text-2xl font-bold text-ink">入金と支払いの予定</h1>
        <p class="mt-1 text-note text-ink-sub">いつ入り、いつ出るかを入れておきます。予定日を過ぎても済みの印がないものは「遅れ」と出ます。取引先の名前は、この画面と社長の画面にだけ出ます。</p>
    </header>

    @if ($notice !== '')
        <p role="status" class="rounded-lg bg-ok-soft px-4 py-3 font-bold text-ok" data-test="schedule-notice">{{ $notice }}</p>
    @endif
    @error('schedule') <p role="alert" class="rounded-lg bg-ng-soft px-4 py-3 font-bold text-ng" data-test="schedule-error">{{ $message }}</p> @enderror

    <div class="grid gap-4 md:grid-cols-2" data-test="schedule-summary">
        @foreach ([ScheduleDirection::In, ScheduleDirection::Out] as $side)
            @php($s = $summary[$side->value])
            <div @class(['rounded-xl border p-5', 'border-ng bg-ng-soft' => $s['late_count'] > 0, 'border-line bg-panel' => $s['late_count'] === 0]) data-test="schedule-summary-{{ $side->value }}">
                <p class="text-note text-ink-sub">まだの{{ $side->label() }}</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['open_count'] }}件・{{ number_format($s['open_yen']) }}円</p>
                @if ($s['late_count'] > 0)
                    <p class="font-bold text-ng" data-test="schedule-late-{{ $side->value }}">うち遅れ {{ $s['late_count'] }}件・{{ number_format($s['late_yen']) }}円</p>
                @else
                    <p class="text-ink-sub">遅れはありません</p>
                @endif
            </div>
        @endforeach
    </div>

    @if ($this->accounts->isEmpty())
        <p class="rounded-xl border border-line bg-panel p-5 text-ink-sub">銀行の口座がまだありません。</p>
    @else
        <form wire:submit="save" class="space-y-4 rounded-xl border border-line bg-panel p-5" data-test="schedule-form">
            <h2 class="text-lg font-bold text-ink">{{ $editingId === null ? '予定を入れる' : '予定を直す' }}</h2>

            <fieldset>
                <legend class="font-bold text-ink">入金か支払いか</legend>
                <div class="mt-1 flex flex-wrap gap-4">
                    @foreach (ScheduleDirection::cases() as $side)
                        <label class="flex items-center gap-2">
                            <input type="radio" wire:model.live="direction" value="{{ $side->value }}" class="size-5 accent-ink" />
                            <span>{{ $side->label() }}</span>
                        </label>
                    @endforeach
                </div>
                @error('direction') <p class="text-note text-ng">{{ $message }}</p> @enderror
            </fieldset>

            <div>
                <label for="schedule-category" class="block font-bold text-ink">種類</label>
                <select id="schedule-category" wire:model.live="category" class="mt-1 w-full rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink">
                    @foreach ($this->categories() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @error('category') <p class="text-note text-ng">{{ $message }}</p> @enderror
            </div>

            <div>
                @if ($partnerAllowed)
                    <label for="schedule-partner" class="block font-bold text-ink">取引先</label>
                    <select id="schedule-partner" wire:model="partnerId" class="mt-1 w-full rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink">
                        <option value="">{{ $isIn ? CashSchedule::INDIVIDUAL_LABEL.'（名前は入れません）' : CashSchedule::NO_PARTNER_LABEL }}</option>
                        @foreach ($this->partners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                        @endforeach
                        @if ($archivedPartner !== null)
                            <option value="{{ $archivedPartner->id }}">{{ $archivedPartner->name }}（使わなくした取引先）</option>
                        @endif
                    </select>
                    <p class="mt-1 text-note text-ink-sub">一覧にない取引先は、下の「取引先」で足します。</p>
                @else
                    <p class="font-bold text-ink">取引先</p>
                    <p class="mt-1 text-ink-sub" data-test="schedule-salary-note">給料は全員の合計を1行で入れます。人の名前や、人ごとの金額は入れません。</p>
                @endif
                @error('partnerId') <p class="text-note text-ng">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="schedule-amount" class="block font-bold text-ink">金額</label>
                <div class="mt-1 flex items-center gap-2">
                    <input id="schedule-amount" type="text" inputmode="numeric" wire:model="amountYen" placeholder="1200000" class="w-56 rounded-lg border border-line bg-paper px-3 py-2 text-right text-body text-ink" />
                    <span class="text-body text-ink">円</span>
                </div>
                @error('amountYen') <p class="text-note text-ng">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="schedule-due" class="block font-bold text-ink">{{ $isIn ? '入金の予定日' : '支払いの予定日' }}</label>
                <input id="schedule-due" type="date" wire:model="dueDate" class="mt-1 w-48 rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink" />
                @error('dueDate') <p class="text-note text-ng">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="schedule-account" class="block font-bold text-ink">口座</label>
                <select id="schedule-account" wire:model="bankAccountId" class="mt-1 w-full rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink">
                    @foreach ($this->accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
                @error('bankAccountId') <p class="text-note text-ng">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-ink px-4 py-2 font-bold text-paper disabled:opacity-60" data-test="schedule-save">{{ $editingId === null ? '予定を入れる' : '直して保存する' }}</button>
                @if ($editingId !== null)
                    <button type="button" wire:click="cancelEdit" class="rounded-lg border border-line bg-paper px-4 py-2 font-bold text-ink">やめる</button>
                @endif
            </div>
        </form>
    @endif

    <div class="space-y-3 rounded-xl border border-line bg-panel p-5">
        <h2 class="text-lg font-bold text-ink">まだの予定</h2>
        @if ($this->openSchedules->isEmpty())
            <p class="text-ink-sub">まだありません。</p>
        @else
            <div>
                <label for="schedule-settle-date" class="block font-bold text-ink">済みにする日</label>
                <input id="schedule-settle-date" type="date" wire:model="settleDate" class="mt-1 w-48 rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink" />
                <p class="mt-1 text-note text-ink-sub">通帳で入金・支払いを確かめた日を選んでから、下の「入金済みにする」「支払済みにする」を押します。</p>
                @error('settleDate') <p class="text-note text-ng">{{ $message }}</p> @enderror
            </div>

            <ul class="divide-y divide-line" data-test="schedule-open-list">
                @foreach ($this->openSchedules as $schedule)
                    @php($judgement = ScheduleDelay::judge($schedule->due_date->toDateString(), null, $today))
                    <li wire:key="schedule-{{ $schedule->id }}" class="space-y-2 py-3" data-test="schedule-row" data-status="{{ $judgement['status'] }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-ink">{{ $schedule->due_date->isoFormat('M月D日(ddd)') }}</span>
                                <span class="rounded bg-paper px-2 py-0.5 text-note font-bold text-ink">{{ $schedule->direction->label() }}</span>
                                <span class="text-ink-sub">{{ $schedule->category->label() }}</span>
                                @if ($schedule->partyLabel() !== null)
                                    <span class="font-bold text-ink">{{ $schedule->partyLabel() }}</span>
                                @endif
                                @if ($judgement['status'] === ScheduleDelay::STATUS_LATE)
                                    <span class="rounded bg-ng-soft px-2 py-0.5 text-note font-bold text-ng" data-test="schedule-late">遅れ（{{ $judgement['days_late'] }}日）</span>
                                @elseif ($judgement['status'] === ScheduleDelay::STATUS_DUE_TODAY)
                                    <span class="rounded bg-warn-soft px-2 py-0.5 text-note font-bold text-warn" data-test="schedule-today">今日</span>
                                @endif
                            </div>
                            <span class="whitespace-nowrap font-bold tabular-nums text-ink">{{ number_format($schedule->amount_yen) }}円</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-note text-ink-sub">{{ $schedule->bankAccount->name }}</span>
                            <button type="button" wire:click="settle('{{ $schedule->id }}')" wire:loading.attr="disabled" class="rounded-lg bg-ink px-3 py-1 text-note font-bold text-paper disabled:opacity-60" data-test="schedule-settle">{{ $schedule->direction->settledLabel() }}にする</button>
                            <button type="button" wire:click="startEdit('{{ $schedule->id }}')" class="rounded-lg border border-line bg-paper px-3 py-1 text-note font-bold text-ink" data-test="schedule-edit">直す</button>
                            <button type="button" wire:click="remove('{{ $schedule->id }}')" wire:confirm="この予定を取り消しますか？" class="rounded-lg border border-line bg-paper px-3 py-1 text-note font-bold text-ink" data-test="schedule-remove">取り消す</button>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($openTotal > $this->openSchedules->count())
                <p class="text-note text-ink-sub" data-test="schedule-open-more">予定日の古い順に {{ $this->openSchedules->count() }}件まで出しています（ほか {{ $openTotal - $this->openSchedules->count() }}件）。上の件数と金額は、出ていない分も入れた合計です。</p>
            @endif
        @endif
    </div>

    <div class="space-y-3 rounded-xl border border-line bg-panel p-5">
        <h2 class="text-lg font-bold text-ink">済みの予定（新しい30件）</h2>
        @if ($this->settledSchedules->isEmpty())
            <p class="text-ink-sub">まだありません。</p>
        @else
            <ul class="divide-y divide-line" data-test="schedule-settled-list">
                @foreach ($this->settledSchedules as $schedule)
                    <li wire:key="settled-{{ $schedule->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3" data-test="schedule-settled-row">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-ok">{{ $schedule->settled_on?->isoFormat('M月D日(ddd)') }}に{{ $schedule->direction->settledLabel() }}</span>
                            <span class="text-ink-sub">{{ $schedule->category->label() }}</span>
                            @if ($schedule->partyLabel() !== null)
                                <span class="text-ink">{{ $schedule->partyLabel() }}</span>
                            @endif
                            <span class="text-note text-ink-sub">予定日 {{ $schedule->due_date->isoFormat('M月D日(ddd)') }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="whitespace-nowrap font-bold tabular-nums text-ink">{{ number_format($schedule->amount_yen) }}円</span>
                            <button type="button" wire:click="unsettle('{{ $schedule->id }}')" wire:confirm="済みを戻しますか？" class="rounded-lg border border-line bg-paper px-3 py-1 text-note font-bold text-ink" data-test="schedule-unsettle">済みを戻す</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="space-y-4 rounded-xl border border-line bg-panel p-5" data-test="partner-section">
        <div>
            <h2 class="text-lg font-bold text-ink">取引先</h2>
            <p class="mt-1 text-note text-ink-sub">会社やお店の名前を入れます。個人のお客さんの名前は入れません（予定では「{{ CashSchedule::INDIVIDUAL_LABEL }}」を選びます）。</p>
        </div>

        <form wire:submit="addPartner" class="flex flex-wrap items-end gap-2" data-test="partner-form">
            <div class="grow">
                <label for="partner-name" class="block font-bold text-ink">足す取引先の名前</label>
                <input id="partner-name" type="text" wire:model="partnerName" maxlength="{{ CashScheduleService::MAX_PARTNER_NAME }}" autocomplete="off" class="mt-1 w-full rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink" />
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="addPartner" class="rounded-lg bg-ink px-4 py-2 font-bold text-paper disabled:opacity-60" data-test="partner-add">足す</button>
            @error('partnerName') <p class="basis-full text-note text-ng">{{ $message }}</p> @enderror
        </form>

        @if ($this->partners->isEmpty())
            <p class="text-ink-sub">まだありません。</p>
        @else
            <ul class="divide-y divide-line" data-test="partner-list">
                @foreach ($this->partners as $partner)
                    <li wire:key="partner-{{ $partner->id }}" class="py-3">
                        @if ($renamingPartnerId === $partner->id)
                            <form wire:submit="saveRename" class="flex flex-wrap items-end gap-2">
                                <div class="grow">
                                    <label for="partner-rename" class="block font-bold text-ink">直した名前</label>
                                    <input id="partner-rename" type="text" wire:model="renameName" maxlength="{{ CashScheduleService::MAX_PARTNER_NAME }}" autocomplete="off" class="mt-1 w-full rounded-lg border border-line bg-paper px-3 py-2 text-body text-ink" />
                                </div>
                                <button type="submit" class="rounded-lg bg-ink px-3 py-2 font-bold text-paper">保存する</button>
                                <button type="button" wire:click="cancelRename" class="rounded-lg border border-line bg-paper px-3 py-2 font-bold text-ink">やめる</button>
                                @error('renameName') <p class="basis-full text-note text-ng">{{ $message }}</p> @enderror
                            </form>
                        @else
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <span class="font-bold text-ink">{{ $partner->name }}</span>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="startRename('{{ $partner->id }}')" class="rounded-lg border border-line bg-paper px-3 py-1 text-note font-bold text-ink" data-test="partner-rename">名前を直す</button>
                                    <button type="button" wire:click="archivePartner('{{ $partner->id }}')" wire:confirm="この取引先を使わなくしますか？入れてある予定はそのまま残ります。" class="rounded-lg border border-line bg-paper px-3 py-1 text-note font-bold text-ink" data-test="partner-archive">使わなくする</button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="text-note text-ink-sub">使わなくした取引先は、同じ名前でもう一度足すと戻ります。</p>
    </div>
</section>
