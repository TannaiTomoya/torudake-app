<?php

use App\Enums\FigureMetric;
use App\Enums\ReportStatus;
use App\Models\Company;
use App\Models\ReportImport;
use App\Models\User;
use App\Services\ReportImportService;
use App\Support\ReadingChecks;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    #[Locked]
    public string $companyId = '';

    public Company $company;

    /** @var mixed */
    public $photo = null;

    public ?string $notice = null;

    public function mount(Company $company): void
    {
        $this->authorize('importReports', $company);

        $this->company = $company;
        $this->companyId = $company->id;
    }

    public function upload(ReportImportService $service): void
    {
        $this->authorize('importReports', $this->company);

        $this->validate(['photo' => ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif']]);

        /** @var User $user */
        $user = Auth::user();
        $service->upload($this->company, $user, $this->photo);

        $this->reset('photo');
        $this->notice = null;
        unset($this->latest);
    }

    public function save(ReportImportService $service): void
    {
        $this->authorize('importReports', $this->company);

        $import = $this->latest;
        if ($import === null) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $count = $service->save($this->company, $user, $import->id);

        $this->notice = "{$count}か月分の売上を保存しました。社長の画面に出ます。";
        unset($this->latest);
    }

    #[Computed]
    public function latest(): ?ReportImport
    {
        return $this->company->reportImports()->latest('created_at')->first();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function existing(): array
    {
        $months = array_column($this->latest?->rows ?? [], 'year_month');

        return $this->company->externalMonthlyFigures()
            ->where('metric', FigureMetric::Sales)
            ->whereIn('year_month', $months)
            ->pluck('amount_yen', 'year_month')
            ->map(fn ($yen): int => (int) $yen)
            ->all();
    }
}; ?>

@php
    $import = $this->latest;
    $existing = $this->existing;
    $threshold = ReadingChecks::THRESHOLDS['total'];
    $yen = fn (int $v): string => number_format($v).'円';
@endphp

<section @if ($import?->status === ReportStatus::Reading) wire:poll.2s @endif class="mx-auto max-w-3xl space-y-6 py-4 md:p-4 text-body text-ink">
    <header class="border-b border-line pb-3">
        <h1 class="text-xl font-bold">帳票から月ごとの売上を入れる</h1>
        <p class="mt-1 text-note text-ink-sub">月別売上（三期比較）の表を撮って送ると、AIが読みます。数字が入るのは「保存」を押したときだけです。</p>
        <a href="{{ route('company.owner', $company) }}" wire:navigate class="mt-2 inline-block font-bold text-brand-ink underline">社長の画面へ戻る</a>
    </header>

    <form wire:submit="upload" class="space-y-3 rounded-2xl border border-line bg-panel p-5">
        <input type="file" accept="image/*" wire:model="photo" class="block w-full text-body" data-test="report-photo">
        @error('photo') <p class="font-bold text-ng">{{ $message }}</p> @enderror
        <button type="submit" class="rounded-xl bg-ink px-5 py-3 font-bold text-paper" wire:loading.attr="disabled">AIに読ませる</button>
    </form>

    @if ($notice)
        <p class="rounded-xl bg-ok-soft p-4 font-bold text-ok" data-test="report-notice">{{ $notice }}</p>
    @endif

    @if ($import)
        <section class="space-y-3" data-test="report-latest">
            <p class="font-bold" data-test="report-status">{{ $import->status->label() }}</p>

            @if (($import->rows ?? []) !== [])
                <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
                    <table class="w-full text-left">
                        <thead class="border-b border-line text-note text-ink-sub">
                            <tr><th class="p-3">月</th><th class="p-3 text-right">AIが読んだ売上</th><th class="p-3 text-right">今の数字</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($import->rows as $row)
                                @php
                                    $low = $row['confidence'] < $threshold;
                                    $current = $existing[$row['year_month']] ?? null;
                                    $differs = $current !== null && $current !== $row['amount_yen'];
                                @endphp
                                <tr class="border-b border-line {{ $low ? 'bg-warn-soft' : '' }}" data-test="report-row-{{ $row['year_month'] }}">
                                    <td class="p-3">{{ \Carbon\CarbonImmutable::parse($row['year_month'].'-01')->isoFormat('YYYY年M月') }}</td>
                                    <td class="p-3 text-right font-bold">
                                        {{ $yen($row['amount_yen']) }}
                                        @if ($low) <span class="ml-2 text-warn" data-test="report-check-{{ $row['year_month'] }}">確かめて</span> @endif
                                    </td>
                                    <td class="p-3 text-right {{ $differs ? 'font-bold text-ng' : 'text-ink-sub' }}">
                                        {{ $current === null ? 'なし' : $yen($current) }}
                                        @if ($differs) <span class="ml-2" data-test="report-differs-{{ $row['year_month'] }}">違う</span> @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($import->status === ReportStatus::Review)
                <button type="button" wire:click="save" class="rounded-xl bg-ink px-5 py-3 font-bold text-paper" data-test="report-save">保存（今の数字を置き換える）</button>
            @endif
        </section>
    @endif
</section>
