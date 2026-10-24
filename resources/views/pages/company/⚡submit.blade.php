<?php

use App\Models\Company;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public function mount(Company $company): void
    {
        $this->authorize('submit', $company);

        $this->company = $company;
        $this->companyId = $company->id;
    }
}; ?>

<section class="mx-auto max-w-xl space-y-6 p-4 text-body text-ink">
    <header class="border-b border-line pb-4">
        <h1 class="text-2xl font-bold text-ink">レシートを送る</h1>
        <p class="mt-1 text-note text-ink-sub">{{ $company->name }}</p>
    </header>
</section>
