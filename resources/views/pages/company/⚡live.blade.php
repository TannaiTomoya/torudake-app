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
        $this->authorize('viewLive', $company);

        $this->company = $company;
        $this->companyId = $company->id;
    }
}; ?>

<section class="mx-auto max-w-6xl space-y-6 p-6 text-live text-ink">
    <header class="border-b border-line pb-4">
        <h1 class="font-bold text-ink">ライブ画面 — {{ $company->name }}</h1>
    </header>
</section>
