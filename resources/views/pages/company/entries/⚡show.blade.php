<?php

use App\Models\Company;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    #[Locked]
    public string $entryId = '';

    public Company $company;

    public function mount(Company $company, string $entry): void
    {
        $this->authorize('approve', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->entryId = $entry;
    }
}; ?>

<section class="mx-auto max-w-4xl space-y-6 p-4 text-body text-ink">
    <header class="border-b border-line pb-4">
        <h1 class="text-2xl font-bold text-ink">仕訳の確認・承認</h1>
        <p class="mt-1 text-note text-ink-sub">{{ $company->name }}</p>
    </header>
</section>
