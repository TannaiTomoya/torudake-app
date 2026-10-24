<?php

use App\Models\Company;
use App\Models\User;
use App\Services\SubmitEvidence;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('レシートを送る')] class extends Component {
    use WithFileUploads;

    #[Locked]
    public string $companyId = '';

    public Company $company;

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public string $note = '';

    /** waiting / confirm / sent / duplicate / failed（送信中は wire:loading で出す） */
    #[Locked]
    public string $state = 'waiting';

    #[Locked]
    public int $monthCount = 0;

    #[Locked]
    public string $duplicateOf = '';

    public function mount(Company $company): void
    {
        $this->authorize('submit', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->monthCount = $company->sourceRecords()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'photo.required' => '写真を選んでください。',
            'photo.max' => '10MBまでの写真にしてください。',
            'photo.mimetypes' => '写真（JPEG・PNG・WebP・HEIC）か PDF を送ってください。',
            'photo.file' => '写真を読み込めませんでした。',
            'photo.uploaded' => '写真を読み込めませんでした。10MBまでの写真にしてください。',
        ];
    }

    public function updatedPhoto(): void
    {
        $this->authorize('submit', $this->company);

        $this->validateOnly('photo');

        $this->state = 'confirm';
    }

    public function send(SubmitEvidence $service): void
    {
        $this->authorize('submit', $this->company);

        $this->validate();

        /** @var User $user */
        $user = Auth::user();

        /** @var TemporaryUploadedFile $photo */
        $photo = $this->photo;

        try {
            $result = $service->submit($this->company, $user, $photo, $this->note);
        } catch (\Throwable $e) {
            report($e);
            $this->state = 'failed';

            return;
        }

        $this->monthCount = $result->monthCount;

        if ($result->duplicate) {
            $existing = $result->source;
            $this->duplicateOf = $existing !== null
                ? $existing->created_at?->isoFormat('M月D日(ddd)').'に送った'.($existing->vendor !== null ? $existing->vendor.'の' : '').'レシートと同じです'
                : '前に送ったレシートと同じです';
            $this->state = 'duplicate';
        } else {
            $this->state = 'sent';
        }

        $this->reset('photo', 'note');
    }

    public function retake(): void
    {
        $this->authorize('submit', $this->company);

        $this->reset('photo', 'note', 'duplicateOf');
        $this->resetValidation();
        $this->state = 'waiting';
    }
}; ?>

<section class="mx-auto flex min-h-[80vh] max-w-md flex-col gap-4 p-4 text-body text-ink">
    <header class="flex items-baseline justify-between border-b border-line pb-3">
        <h1 class="text-xl font-bold text-ink">送る</h1>
        <p class="text-note text-ink-sub">{{ $company->name }}</p>
    </header>

    {{-- 1 待ち --}}
    @if ($state === 'waiting')
        <p class="text-note text-ink-sub">今月送った枚数 <span class="font-bold text-ink" data-test="month-count">{{ $monthCount }}枚</span></p>

        <label class="flex flex-1 cursor-pointer flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-muted bg-panel p-8 text-center">
            <input type="file" class="sr-only" accept="image/*,application/pdf" capture="environment" wire:model="photo" data-test="camera-input">
            <flux:icon.camera class="size-12 text-ink" />
            <span class="text-lg font-bold text-ink">レシートを撮る</span>
            <span class="text-note text-ink-sub">押すとカメラが開きます</span>
        </label>

        <div wire:loading wire:target="photo" class="rounded-lg bg-panel p-3 text-center text-note text-ink-sub">写真を読み込んでいます…</div>

        @error('photo')
            <p class="rounded-lg bg-ng-soft p-3 text-note font-bold text-ng" role="alert">{{ $message }}</p>
        @enderror

        <label class="block w-full cursor-pointer rounded-xl bg-brand py-3 text-center text-lg font-bold text-ink">
            <input type="file" class="sr-only" accept="image/*,application/pdf" capture="environment" wire:model="photo">
            撮る
        </label>
        <label class="block w-full cursor-pointer rounded-xl border-2 border-ink bg-paper py-3 text-center font-bold text-ink">
            <input type="file" class="sr-only" accept="image/*,application/pdf" wire:model="photo" data-test="library-input">
            写真を選ぶ
        </label>
    @endif

    {{-- 2 確認 ／ 3 送信中 --}}
    @if ($state === 'confirm' || $state === 'failed')
        @if ($state === 'failed')
            {{-- 6 送れなかった --}}
            <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-2xl bg-ng-soft p-8 text-center" role="alert" wire:loading.remove wire:target="send">
                <flux:icon.x-mark class="size-12 text-ng" />
                <p class="text-lg font-bold text-ng">送れませんでした</p>
                <p class="text-note text-ng">電波の良い所で<br>もう一度押してください</p>
            </div>
        @else
            <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-2xl border border-line bg-paper p-4" wire:loading.remove wire:target="send">
                @if ($photo && $photo->isPreviewable())
                    <img src="{{ $photo->temporaryUrl() }}" alt="撮ったレシート" class="max-h-[50vh] rounded-lg object-contain">
                @else
                    <flux:icon.document-text class="size-16 text-muted" />
                    <p class="text-note text-ink-sub">{{ $photo?->getClientOriginalName() }}</p>
                @endif
                <p class="text-note text-ink-sub">文字が読めますか？</p>
            </div>

            <details class="rounded-lg border border-line bg-panel p-3" wire:loading.remove wire:target="send">
                <summary class="cursor-pointer text-note font-bold text-ink-sub">一言メモ（任意）</summary>
                <input type="text" wire:model="note" maxlength="200" class="mt-2 w-full rounded border border-line bg-paper px-3 py-2 text-body text-ink" placeholder="例：〇〇様の現場の部品">
                @error('note')
                    <p class="mt-1 text-note text-ng">{{ $message }}</p>
                @enderror
            </details>
        @endif

        {{-- 3 送信中 --}}
        <div wire:loading.flex wire:target="send" class="flex-1 flex-col items-center justify-center gap-3 rounded-2xl border border-line bg-paper p-8 text-center"
             x-data="{ s: 0 }" x-init="setInterval(() => { s = $el.offsetParent === null ? 0 : s + 1 }, 1000)">
            <span class="size-10 animate-spin rounded-full border-4 border-line border-t-brand"></span>
            <p class="text-lg font-bold text-ink">送っています…</p>
            <p class="text-note text-ink-sub"><span x-text="s"></span>秒</p>
        </div>

        @if ($state === 'confirm')
            <button type="button" wire:click="retake" wire:loading.remove wire:target="send" class="w-full rounded-xl border-2 border-ink bg-paper py-3 font-bold text-ink">撮り直す</button>
        @endif

        <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send"
                class="w-full rounded-xl bg-brand py-3 text-lg font-bold text-ink disabled:cursor-not-allowed disabled:border-2 disabled:border-dashed disabled:border-ink disabled:bg-panel"
                data-test="send-button">
            <span wire:loading.remove wire:target="send">{{ $state === 'failed' ? 'もう一度送る' : '送る' }}</span>
            <span wire:loading wire:target="send">送っています…</span>
        </button>
    @endif

    {{-- 4 送れました --}}
    @if ($state === 'sent')
        <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-2xl bg-ok-soft p-8 text-center" role="status">
            <flux:icon.check class="size-12 text-ok" />
            <p class="text-lg font-bold text-ok">送れました</p>
            <p class="text-note text-ok">今月 {{ $monthCount }}枚目<br>AIが下書きを作ります</p>
        </div>
        <button type="button" wire:click="retake" class="w-full rounded-xl bg-brand py-3 text-lg font-bold text-ink">続けて撮る</button>
    @endif

    {{-- 5 同じ写真 --}}
    @if ($state === 'duplicate')
        <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-2xl bg-warn-soft p-8 text-center" role="status">
            <span class="text-5xl font-bold text-warn">!</span>
            <p class="text-lg font-bold text-warn">この写真はもう届いています</p>
            <p class="text-note text-warn">{{ $duplicateOf }}</p>
        </div>
        <button type="button" wire:click="retake" class="w-full rounded-xl bg-brand py-3 text-lg font-bold text-ink">別のレシートを撮る</button>
    @endif
</section>
