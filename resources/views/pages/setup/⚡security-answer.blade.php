<?php

use App\Models\User;
use App\Services\SecurityAnswer;
use App\Services\TrustedDevices;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('はじめの設定')] class extends Component {
    public string $answer = '';

    public bool $trustDevice = true;

    public function mount(): void
    {
        if ($this->user()->hasSecurityAnswer()) {
            $this->redirect($this->user()->homeUrl());
        }
    }

    public function save(SecurityAnswer $answers, TrustedDevices $devices): void
    {
        $this->validate(
            ['answer' => ['required', 'string', 'max:'.SecurityAnswer::MAX_LENGTH]],
            attributes: ['answer' => '答え'],
        );

        if (SecurityAnswer::normalize($this->answer) === '') {
            $this->addError('answer', '答えを入れてください。');

            return;
        }

        $user = $this->user();

        // もう決めてある人の答えは、ここでは書き換えない（変えるのは設定の「セキュリティ」）
        if ($answers->set($user, $this->answer, onlyIfUnset: true) && $this->trustDevice) {
            $devices->issue(request(), $user);
        }

        $this->reset('answer');
        $this->redirect($user->homeUrl());
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}; ?>

<div class="flex flex-col gap-6" data-test="security-answer-setup">
    <x-auth-header title="はじめの設定（1回だけ）" description="新しい端末で入るときに聞く、秘密の質問の答えを決めます。" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input
            wire:model="answer"
            :label="\App\Services\SecurityAnswer::QUESTION"
            :description="\App\Services\SecurityAnswer::HINT"
            type="text"
            maxlength="{{ \App\Services\SecurityAnswer::MAX_LENGTH }}"
            required
            autofocus
            autocomplete="off"
        />

        <flux:checkbox wire:model="trustDevice" label="この端末を覚える（30日は質問しません）" />

        <flux:button variant="primary" type="submit" class="w-full !h-14 !text-lg" data-test="security-answer-save">
            決めて始める
        </flux:button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="text-center">
        @csrf
        <button type="submit" class="text-body text-ink-sub underline">ログアウト</button>
    </form>
</div>
