<?php

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Company;
use App\Models\User;
use App\Services\CategoryRuleBoard;
use App\Services\CategoryRuleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $companyId = '';

    public Company $company;

    public string $keyword = '';

    public string $categoryId = '';

    public string $notice = '';

    public ?string $editingId = null;

    public function mount(Company $company): void
    {
        $this->authorize('manageCategoryRules', $company);

        $this->company = $company;
        $this->companyId = $company->id;
        $this->categoryId = $this->categories->first()->id ?? '';
    }

    public function hydrate(): void
    {
        $this->authorize('manageCategoryRules', $this->company);
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->company->categories()
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, CategoryRule>
     */
    #[Computed]
    public function rules(): Collection
    {
        return app(CategoryRuleBoard::class)->rules($this->company);
    }

    /**
     * @return list<array{keyword: string, category_id: string, category_name: string, count: int}>
     */
    #[Computed]
    public function candidates(): array
    {
        return app(CategoryRuleBoard::class)->candidates($this->company);
    }

    public function startEdit(string $ruleId): void
    {
        $this->authorize('manageCategoryRules', $this->company);
        $rule = $this->company->categoryRules()->findOrFail($ruleId);
        $this->editingId = $rule->id;
        $this->keyword = $rule->keyword;
        $this->categoryId = $rule->category_id;
        $this->notice = '';
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->keyword = '';
        $this->categoryId = $this->categories->first()->id ?? '';
        $this->resetErrorBag();
    }

    public function save(CategoryRuleService $service): void
    {
        $this->authorize('manageCategoryRules', $this->company);
        $this->notice = '';

        $this->validate([
            'keyword' => ['required', 'string', 'max:'.CategoryRule::KEYWORD_MAX],
            'categoryId' => ['required', 'string'],
        ], [
            'keyword.*' => 'キーワードを入れてください。',
            'categoryId.*' => '分類を選んでください。',
        ]);

        /** @var User $user */
        $user = Auth::user();

        if ($this->editingId === null) {
            $service->add($this->company, $user, $this->keyword, $this->categoryId);
            $this->notice = 'ルールを一番上に足しました。';
        } else {
            $service->update($this->company, $user, $this->editingId, $this->keyword, $this->categoryId);
            $this->notice = 'ルールを保存しました。すでにある下書きは変わりません。';
        }

        $this->cancelEdit();
        unset($this->rules, $this->candidates);
    }

    public function adoptCandidate(int $index, CategoryRuleService $service): void
    {
        $this->authorize('manageCategoryRules', $this->company);
        $candidate = $this->candidates[$index] ?? null;
        if ($candidate === null) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $service->add($this->company, $user, $candidate['keyword'], $candidate['category_id']);
        $this->notice = '候補をルールとして一番上に足しました。';
        unset($this->rules, $this->candidates);
    }

    public function remove(string $ruleId, CategoryRuleService $service): void
    {
        $this->authorize('manageCategoryRules', $this->company);
        /** @var User $user */
        $user = Auth::user();
        $service->remove($this->company, $user, $ruleId);
        $this->notice = 'ルールを消しました。すでにある下書きは変わりません。';
        if ($this->editingId === $ruleId) {
            $this->cancelEdit();
        }
        unset($this->rules, $this->candidates);
    }

    public function moveUp(string $ruleId, CategoryRuleService $service): void
    {
        $this->authorize('manageCategoryRules', $this->company);
        /** @var User $user */
        $user = Auth::user();
        $service->moveUp($this->company, $user, $ruleId);
        unset($this->rules);
    }

    public function moveDown(string $ruleId, CategoryRuleService $service): void
    {
        $this->authorize('manageCategoryRules', $this->company);
        /** @var User $user */
        $user = Auth::user();
        $service->moveDown($this->company, $user, $ruleId);
        unset($this->rules);
    }
}; ?>

<section class="mx-auto max-w-4xl space-y-8 p-4 text-body text-ink" data-test="category-rules-page">
    <header class="border-b border-line pb-4">
        <h1 class="text-2xl font-bold text-ink">分類のルール</h1>
        <p class="mt-1 text-note text-ink-sub">店名にキーワードを含むとき、その分類を下書きに入れます。上の行から当てます。新しく足したルールは一番上です。すでにある下書きは変わりません。</p>
    </header>

    @if ($notice !== '')
        <p role="status" class="rounded-lg bg-ok-soft px-4 py-3 font-bold text-ok" data-test="category-rule-notice">{{ $notice }}</p>
    @endif

    <form wire:submit="save" class="space-y-4 rounded-xl border border-line bg-panel p-4" data-test="category-rule-form">
        <h2 class="font-bold">{{ $editingId === null ? 'ルールを足す' : 'ルールを直す' }}</h2>
        <div class="flex flex-wrap gap-4">
            <div>
                <label for="rule-keyword" class="block text-note text-ink-sub">キーワード（店名に含む）</label>
                <input id="rule-keyword" type="text" wire:model="keyword" maxlength="60" class="mt-1 rounded-lg border border-line bg-paper px-3 py-2 text-ink" data-test="category-rule-keyword">
                @error('keyword') <p class="mt-1 text-note text-ng">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="rule-category" class="block text-note text-ink-sub">分類</label>
                <select id="rule-category" wire:model="categoryId" class="mt-1 rounded-lg border border-line bg-paper px-3 py-2 text-ink" data-test="category-rule-category">
                    @foreach ($this->categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('categoryId') <p class="mt-1 text-note text-ng">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="submit" class="rounded-lg bg-brand px-4 py-2 font-bold text-brand-ink" data-test="category-rule-save">保存</button>
            @if ($editingId !== null)
                <button type="button" wire:click="cancelEdit" class="rounded-lg border border-line px-4 py-2 font-bold text-ink" data-test="category-rule-cancel">やめる</button>
            @endif
        </div>
    </form>

    <div class="space-y-3" data-test="category-rule-list">
        <h2 class="font-bold">ルール一覧（上が優先）</h2>
        @if ($this->rules->isEmpty())
            <p class="text-ink-sub">まだルールがありません。</p>
        @else
            <ul class="divide-y divide-line rounded-xl border border-line bg-paper">
                @foreach ($this->rules as $rule)
                    <li wire:key="rule-{{ $rule->id }}" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3" data-test="category-rule-row" data-position="{{ $rule->position }}">
                        <div>
                            <span class="font-bold" data-test="category-rule-row-keyword">「{{ $rule->keyword }}」</span>
                            <span class="text-ink-sub">を含む → </span>
                            <span class="font-bold" data-test="category-rule-row-category">{{ $rule->category?->name ?? '（使われていない分類）' }}</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="moveUp('{{ $rule->id }}')" class="rounded border border-line px-2 py-1 text-note font-bold" data-test="category-rule-up" @disabled($rule->position === 0)>上へ</button>
                            <button type="button" wire:click="moveDown('{{ $rule->id }}')" class="rounded border border-line px-2 py-1 text-note font-bold" data-test="category-rule-down">下へ</button>
                            <button type="button" wire:click="startEdit('{{ $rule->id }}')" class="rounded border border-line px-2 py-1 text-note font-bold" data-test="category-rule-edit">直す</button>
                            <button type="button" wire:click="remove('{{ $rule->id }}')" wire:confirm="このルールを消しますか？すでにある下書きは変わりません。" class="rounded border border-ng px-2 py-1 text-note font-bold text-ng" data-test="category-rule-remove">消す</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="space-y-3" data-test="category-rule-candidates">
        <h2 class="font-bold">直した記録からの候補</h2>
        <p class="text-note text-ink-sub">分類を直した店名から出しています。採用は人が決めます。自動ではルールになりません。</p>
        @if ($this->candidates === [])
            <p class="text-ink-sub">候補はまだありません。</p>
        @else
            <ul class="divide-y divide-line rounded-xl border border-line bg-paper">
                @foreach ($this->candidates as $index => $candidate)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3" data-test="category-rule-candidate">
                        <span>
                            <span class="font-bold">「{{ $candidate['keyword'] }}」</span>
                            <span class="text-ink-sub">→ {{ $candidate['category_name'] }}（{{ $candidate['count'] }}件）</span>
                        </span>
                        <button
                            type="button"
                            wire:click="adoptCandidate({{ $index }})"
                            class="rounded-lg border-2 border-ink bg-paper px-3 py-1 font-bold text-ink"
                            data-test="category-rule-adopt"
                        >採用してルールにする</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
