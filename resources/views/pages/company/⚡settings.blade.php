<?php

use App\Models\Company;
use App\Models\User;
use App\Services\CompanySettingsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public string $name = '';

    public int $fiscalYearEndMonth = 7;

    public bool $allowSelfApproval = false;

    public function mount(Company $company): void
    {
        $this->authorize('manageSettings', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->name = $company->name;
        $this->fiscalYearEndMonth = $company->fiscal_year_end_month;
        $this->allowSelfApproval = $company->allow_self_approval;
    }

    public function saveCompany(CompanySettingsService $service): void
    {
        $this->authorize('manageSettings', $this->company);

        /** @var array{name: string, fiscalYearEndMonth: int, allowSelfApproval: bool} $validated */
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'fiscalYearEndMonth' => ['required', 'integer', 'min:1', 'max:12'],
            'allowSelfApproval' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $this->company = $service->updateCompany($this->company, $user, [
            'name' => $validated['name'],
            'fiscal_year_end_month' => $validated['fiscalYearEndMonth'],
            'allow_self_approval' => $validated['allowSelfApproval'],
        ]);
    }

    public function renameCategory(string $categoryId, string $newName, CompanySettingsService $service): void
    {
        $this->authorize('manageSettings', $this->company);

        $trimmed = trim($newName);
        if ($trimmed === '' || mb_strlen($trimmed) > 100) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();

        $service->renameCategory($this->company, $user, $categoryId, $trimmed);
    }

    public function setDefaultBankAccount(string $bankAccountId, CompanySettingsService $service): void
    {
        $this->authorize('manageSettings', $this->company);

        /** @var User $user */
        $user = Auth::user();

        $service->setDefaultBankAccount($this->company, $user, $bankAccountId);
    }
}; ?>

<section class="mx-auto max-w-4xl space-y-8 p-4 text-body text-ink">
    <header class="border-b border-line pb-4">
        <h1 class="text-2xl font-bold text-ink">会社設定</h1>
        <p class="mt-1 text-note text-ink-sub">{{ $company->name }}</p>
    </header>

    <form wire:submit="saveCompany" class="space-y-4 rounded-xl border border-line bg-panel p-5">
        <h2 class="text-lg font-bold text-ink">基本情報</h2>

        <div>
            <label for="company-name" class="block font-bold text-ink">会社名</label>
            <input id="company-name" type="text" wire:model="name" class="mt-1 w-full rounded border border-line bg-paper px-3 py-2 text-body text-ink" />
        </div>

        <div>
            <label for="fiscal-month" class="block font-bold text-ink">決算月</label>
            <input id="fiscal-month" type="number" min="1" max="12" wire:model="fiscalYearEndMonth" class="mt-1 w-32 rounded border border-line bg-paper px-3 py-2 text-body text-ink" />
        </div>

        <div class="flex items-center gap-2">
            <input id="allow-self-approval" type="checkbox" wire:model="allowSelfApproval" class="size-5 rounded border-line" />
            <label for="allow-self-approval" class="text-body text-ink">自己承認を許可する</label>
        </div>

        <button type="submit" class="rounded-lg bg-ink px-4 py-2 font-bold text-paper">保存する</button>
    </form>

    <div class="space-y-3 rounded-xl border border-line bg-panel p-5">
        <h2 class="text-lg font-bold text-ink">分類と勘定科目</h2>
        <ul class="divide-y divide-line">
            @foreach ($company->categories()->with('account')->orderBy('sort_order')->get() as $category)
                <li class="flex items-center justify-between py-2">
                    <span class="font-bold text-ink">{{ $category->name }}（{{ $category->direction->label() }}）</span>
                    <span class="text-note text-ink-sub">→ {{ $category->account?->name }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="space-y-3 rounded-xl border border-line bg-panel p-5">
        <h2 class="text-lg font-bold text-ink">口座</h2>
        <ul class="divide-y divide-line">
            @foreach ($company->bankAccounts()->with('account')->get() as $bankAccount)
                <li class="flex items-center justify-between py-2">
                    <div>
                        <span class="font-bold text-ink">{{ $bankAccount->name }}</span>
                        <span class="ml-2 text-note text-ink-sub">（{{ $bankAccount->kind->label() }} / {{ $bankAccount->account?->name }}）</span>
                    </div>
                    @if ($bankAccount->is_default_for_receipts)
                        <span class="rounded bg-ok-soft px-2 py-0.5 text-note font-bold text-ok">既定の支払い元</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    <div class="space-y-3 rounded-xl border border-line bg-panel p-5">
        <h2 class="text-lg font-bold text-ink">メンバーの一覧</h2>
        <ul class="divide-y divide-line">
            @foreach ($company->memberships()->with('user')->get() as $membership)
                <li class="flex items-center justify-between py-2">
                    <span class="font-bold text-ink">{{ $membership->user?->name }}（{{ $membership->user?->email }}）</span>
                    <span class="text-note text-ink-sub">
                        {{ $membership->role->label() }}
                        @if ($membership->submit_only)
                            （送るだけ）
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
</section>
