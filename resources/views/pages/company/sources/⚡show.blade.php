<?php

use App\Models\Company;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    #[Locked]
    public string $sourceId = '';

    public Company $company;

    public function mount(Company $company, string $source): void
    {
        $this->authorize('editDraft', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->sourceId = $source;
    }
}; ?>

<section class="mx-auto max-w-4xl space-y-6 p-4 text-body text-ink">
    <header class="border-b border-line pb-4">
        <h1 class="text-2xl font-bold text-ink">下書きを直す</h1>
        <p class="mt-1 text-note text-ink-sub">{{ $company->name }}</p>
    </header>
</section>
