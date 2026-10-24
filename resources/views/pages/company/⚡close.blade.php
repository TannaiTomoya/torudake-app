<?php

use App\Models\Company;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    #[Locked]
    public string $month = '';

    public Company $company;

    public function mount(Company $company, string $month): void
    {
        $this->authorize('viewClose', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->month = $month;
    }
}; ?>

<section class="mx-auto max-w-4xl space-y-6 p-4 text-body text-ink">
    <header class="border-b border-line pb-4">
        <h1 class="text-2xl font-bold text-ink">締めナビ（{{ $month }}）</h1>
        <p class="mt-1 text-note text-ink-sub">{{ $company->name }}</p>
    </header>
</section>
