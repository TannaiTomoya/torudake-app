<?php

use App\Models\Company;
use App\Models\User;
use App\Services\SubmitEvidence;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('レシートを送る')] class extends Component {
    use WithFileUploads;

    public const MAX_PHOTOS = 10;

    private const PHOTO_RULES = ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf'];

    #[Locked]
    public string $companyId = '';

    public Company $company;

    /** カメラで撮った1枚（撮るたびに下の並びに足す） */
    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    /** 写真のライブラリから選んだ数枚 */
    /** @var array<int, TemporaryUploadedFile> */
    public $picked = [];

    /** 送る前にためている写真 */
    /** @var array<int, TemporaryUploadedFile> */
    public $photos = [];

    public string $note = '';

    /** waiting / confirm / sent / duplicate / failed（送信中は wire:loading で出す） */
    #[Locked]
    public string $state = 'waiting';

    #[Locked]
    public int $monthCount = 0;

    #[Locked]
    public string $duplicateOf = '';

    #[Locked]
    public int $sentCount = 0;

    #[Locked]
    public int $duplicateCount = 0;

    #[Locked]
    public int $failedCount = 0;

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
     * @return array<string, string>
     */
    protected function messages(): array
    {
        $messages = [];
        foreach (['photo', 'picked.*', 'photos.*'] as $key) {
            $messages["{$key}.required"] = '写真を選んでください。';
            $messages["{$key}.max"] = '10MBまでの写真にしてください。';
            $messages["{$key}.mimetypes"] = '写真（JPEG・PNG・WebP・HEIC）か PDF を送ってください。';
            $messages["{$key}.file"] = '写真を読み込めませんでした。';
            $messages["{$key}.uploaded"] = '写真を読み込めませんでした。10MBまでの写真にしてください。';
        }

        return $messages;
    }

    public function updatedPhoto(): void
    {
        $this->authorize('submit', $this->company);

        $this->validate(['photo' => self::PHOTO_RULES]);

        /** @var TemporaryUploadedFile $photo */
        $photo = $this->photo;
        $this->addToTray([$photo]);
        $this->reset('photo');
    }

    public function updatedPicked(): void
    {
        $this->authorize('submit', $this->company);

        $this->validate(['picked' => ['array'], 'picked.*' => self::PHOTO_RULES]);

        $this->addToTray(array_values($this->picked));
        $this->reset('picked');
    }

    /**
     * @param  list<TemporaryUploadedFile>  $files
     */
    private function addToTray(array $files): void
    {
        $this->resetErrorBag();

        foreach ($files as $file) {
            if (count($this->photos) >= self::MAX_PHOTOS) {
                $this->addError('photo', '1回に送れるのは'.self::MAX_PHOTOS.'枚までです。いったん送ってから続けてください。');
                break;
            }
            $this->photos[] = $file;
        }

        $this->photos = array_values($this->photos);
        $this->state = $this->photos === [] ? 'waiting' : 'confirm';
    }

    public function removePhoto(int $index): void
    {
        $this->authorize('submit', $this->company);

        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
        $this->state = $this->photos === [] ? 'waiting' : 'confirm';
    }

    public function send(SubmitEvidence $service): void
    {
        $this->authorize('submit', $this->company);
        $this->resetErrorBag();

        if ($this->photos === []) {
            $this->addError('photo', '写真を選んでください。');

            return;
        }

        $this->validate([
            'photos' => ['array', 'max:'.self::MAX_PHOTOS],
            'photos.*' => self::PHOTO_RULES,
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $batchId = Str::uuid7()->toString();

        $this->sentCount = 0;
        $this->duplicateCount = 0;
        $this->duplicateOf = '';
        $failed = [];

        foreach ($this->photos as $photo) {
            try {
                $result = $service->submit($this->company, $user, $photo, $this->note, $batchId);
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $photo;

                continue;
            }

            $this->monthCount = $result->monthCount;

            if ($result->duplicate) {
                $this->duplicateCount++;
                $existing = $result->source;
                $this->duplicateOf = $existing !== null
                    ? $existing->created_at?->isoFormat('M月D日(ddd)').'に送った'.($existing->vendor !== null ? $existing->vendor.'の' : '').'レシートと同じです'
                    : '前に送ったレシートと同じです';
            } else {
                $this->sentCount++;
            }
        }

        $this->failedCount = count($failed);
        $this->photos = $failed;

        $this->state = match (true) {
            $this->sentCount > 0 => 'sent',
            $this->failedCount > 0 => 'failed',
            default => 'duplicate',
        };

        if ($this->failedCount === 0) {
            $this->reset('note');
        }
    }

    public function retake(): void
    {
        $this->authorize('submit', $this->company);

        $this->reset('photo', 'picked', 'photos', 'note', 'duplicateOf', 'sentCount', 'duplicateCount', 'failedCount');
        $this->resetValidation();
        $this->state = 'waiting';
    }
}; ?>

<section class="mx-auto flex min-h-[80vh] max-w-md flex-col gap-4 p-4 text-body text-ink">
    <header class="flex items-baseline justify-between gap-3 border-b border-line pb-3">
        <h1 class="text-xl font-bold text-ink">レシートを送る</h1>
        <div class="flex flex-wrap items-baseline justify-end gap-x-3 text-note text-ink-sub">
            <p>{{ $company->name }}</p>
            @if (auth()->user()?->activeMembershipFor($company)?->submit_only)
                <a href="{{ route('profile.edit') }}" wire:navigate class="underline" data-test="submit-settings">設定</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="cursor-pointer underline" data-test="submit-logout">ログアウト</button>
                </form>
            @endif
        </div>
    </header>

    @if (auth()->user()?->two_factor_confirmed_at === null)
        @include('partials.two-factor-first')
    @else
    {{-- 1 待ち --}}
    @if ($state === 'waiting')
        @if ($monthCount === 0)
            <ol class="space-y-1 rounded-2xl bg-panel p-4 text-body text-ink" data-test="first-guide">
                <li>① レシートを撮る（何枚でも）</li>
                <li>② 「まとめて送る」を押す</li>
                <li>③ おわり。金額を書き写す必要はありません。経理が確かめて帳簿に入れます。</li>
            </ol>
        @else
            <p class="text-note text-ink-sub">今月送った枚数 <span class="font-bold text-ink" data-test="month-count">{{ $monthCount }}枚</span></p>
        @endif

        <label class="flex flex-1 cursor-pointer flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-muted bg-panel p-8 text-center">
            <input type="file" class="sr-only" accept="image/*,application/pdf" capture="environment" wire:model="photo" data-test="camera-input">
            <flux:icon.camera class="size-12 text-ink" />
            <span class="text-lg font-bold text-ink">レシートを撮る</span>
            <span class="text-note text-ink-sub">撮るたびにためて、まとめて送れます</span>
        </label>
    @endif

    {{-- 2 確認（ためている写真） ／ 6 送れなかった写真 --}}
    @if (($state === 'confirm' || $state === 'failed' || $state === 'sent') && $photos !== [])
        @if ($state === 'failed')
            <div class="flex flex-col items-center gap-2 rounded-2xl bg-ng-soft p-6 text-center" role="alert" wire:loading.remove wire:target="send">
                <flux:icon.x-mark class="size-10 text-ng" />
                <p class="text-lg font-bold text-ng">送れませんでした</p>
                <p class="text-note text-ng">電波の良い所で<br>もう一度押してください</p>
            </div>
        @elseif ($state === 'sent')
            <p class="rounded-lg bg-ng-soft p-3 text-note font-bold text-ng" role="alert" data-test="failed-count">{{ $failedCount }}枚は送れませんでした。もう一度押してください</p>
        @endif

        <ul class="grid grid-cols-3 gap-2" data-test="tray" wire:loading.remove wire:target="send">
            @foreach ($photos as $index => $tray)
                <li wire:key="tray-{{ $index }}-{{ $tray->getFilename() }}" class="relative aspect-[3/4] overflow-hidden rounded-lg border border-line bg-panel">
                    @if ($tray->isPreviewable())
                        <img src="{{ $tray->temporaryUrl() }}" alt="{{ $index + 1 }}枚目" class="size-full object-cover">
                    @else
                        <div class="flex size-full flex-col items-center justify-center gap-1 p-1">
                            <flux:icon.document-text class="size-8 text-ink-sub" />
                            <span class="line-clamp-2 break-all text-note text-ink-sub">{{ $tray->getClientOriginalName() }}</span>
                        </div>
                    @endif
                    <button type="button" wire:click="removePhoto({{ $index }})" class="absolute right-1 top-1 flex size-11 items-center justify-center rounded-full bg-ink text-lg font-bold text-paper" aria-label="{{ $index + 1 }}枚目を外す">×</button>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($state === 'waiting' || (($state === 'confirm' || $state === 'failed') && $photos !== []))
        <div wire:loading wire:target="photo,picked" class="rounded-lg bg-panel p-3 text-center text-note text-ink-sub">写真を読み込んでいます…</div>

        @error('photo') <p class="rounded-lg bg-ng-soft p-3 text-note font-bold text-ng" role="alert">{{ $message }}</p> @enderror
        @error('picked.*') <p class="rounded-lg bg-ng-soft p-3 text-note font-bold text-ng" role="alert">{{ $message }}</p> @enderror
        @error('photos.*') <p class="rounded-lg bg-ng-soft p-3 text-note font-bold text-ng" role="alert">{{ $message }}</p> @enderror

        @if (count($photos) < $this::MAX_PHOTOS)
            <div class="grid grid-cols-2 gap-2" wire:loading.remove wire:target="send">
                <label class="block cursor-pointer rounded-xl {{ $photos === [] ? 'bg-brand' : 'border-2 border-ink bg-paper' }} py-3 text-center text-lg font-bold text-ink">
                    <input type="file" class="sr-only" accept="image/*,application/pdf" capture="environment" wire:model="photo">
                    {{ $photos === [] ? '撮る' : 'もう1枚撮る' }}
                </label>
                <label class="block cursor-pointer rounded-xl border-2 border-ink bg-paper py-3 text-center font-bold text-ink">
                    <input type="file" class="sr-only" accept="image/*,application/pdf" multiple wire:model="picked" data-test="library-input">
                    写真を選ぶ
                </label>
            </div>
        @endif
    @endif

    @if (($state === 'confirm' || $state === 'failed') && $photos !== [])
        <details class="rounded-lg border border-line bg-panel p-3" wire:loading.remove wire:target="send">
            <summary class="cursor-pointer text-note font-bold text-ink-sub">一言メモ（任意・まとめて送る全部に付きます）</summary>
            <input type="text" wire:model="note" maxlength="200" class="mt-2 w-full rounded border border-line bg-paper px-3 py-2 text-body text-ink" placeholder="例：〇〇様の現場の部品">
            @error('note') <p class="mt-1 text-note text-ng">{{ $message }}</p> @enderror
        </details>

        {{-- 3 送信中 --}}
        <div wire:loading.flex wire:target="send" class="flex-1 flex-col items-center justify-center gap-3 rounded-2xl border border-line bg-paper p-8 text-center"
             x-data="{ s: 0 }" x-init="setInterval(() => { s = $el.offsetParent === null ? 0 : s + 1 }, 1000)">
            <span class="size-10 animate-spin rounded-full border-4 border-line border-t-brand"></span>
            <p class="text-lg font-bold text-ink">{{ count($photos) }}枚を送っています…</p>
            <p class="text-note text-ink-sub"><span x-text="s"></span>秒</p>
        </div>

        <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send,photo,picked"
                class="w-full rounded-xl bg-brand py-4 text-lg font-bold text-ink disabled:cursor-not-allowed disabled:border-2 disabled:border-dashed disabled:border-ink disabled:bg-panel"
                data-test="send-button">
            <span wire:loading.remove wire:target="send">{{ $state === 'failed' ? 'もう一度送る' : 'まとめて送る' }}（{{ count($photos) }}枚）</span>
            <span wire:loading wire:target="send">送っています…</span>
        </button>

        @if ($state === 'confirm')
            <button type="button" wire:click="retake" wire:loading.remove wire:target="send" class="w-full rounded-xl border-2 border-ink bg-paper py-3 font-bold text-ink">全部やめる</button>
        @endif
    @endif

    {{-- 4 送れました --}}
    @if ($state === 'sent')
        <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-2xl bg-ok-soft p-8 text-center" role="status">
            <flux:icon.check class="size-12 text-ok" />
            <p class="text-lg font-bold text-ok" data-test="sent-count">送れました（{{ $sentCount }}枚）</p>
            @if ($duplicateCount > 0)
                <p class="text-note font-bold text-warn" data-test="duplicate-count">うち{{ $duplicateCount }}枚は、もう届いている写真でした</p>
            @endif
            <p class="text-note text-ok">今月 {{ $monthCount }}枚目まで<br>AIが下書きを作ります</p>
        </div>
        @if ($photos !== [])
            <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send" class="w-full rounded-xl bg-brand py-4 text-lg font-bold text-ink" data-test="send-button">
                もう一度送る（{{ count($photos) }}枚）
            </button>
        @else
            <button type="button" wire:click="retake" class="w-full rounded-xl bg-brand py-3 text-lg font-bold text-ink">続けて撮る</button>
        @endif
    @endif

    {{-- 5 同じ写真 --}}
    @if ($state === 'duplicate')
        <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-2xl bg-warn-soft p-8 text-center" role="status">
            <span class="text-5xl font-bold text-warn">!</span>
            <p class="text-lg font-bold text-warn">{{ $duplicateCount > 1 ? $duplicateCount.'枚とも、もう届いています' : 'この写真はもう届いています' }}</p>
            <p class="text-note text-warn">{{ $duplicateOf }}</p>
        </div>
        <button type="button" wire:click="retake" class="w-full rounded-xl bg-brand py-3 text-lg font-bold text-ink">別のレシートを撮る</button>
    @endif
    @endif
</section>
