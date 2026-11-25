<?php

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Services\SecurityAnswer;
use App\Services\TrustedDevices;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public string $security_answer = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    /**
     * 秘密の質問の答えを変える（パスワード再確認のあとの画面）。ほかの端末は、次のログインで新しい答えを聞く。
     */
    public function changeSecurityAnswer(SecurityAnswer $answers): void
    {
        $this->validate(
            ['security_answer' => ['required', 'string', 'max:'.SecurityAnswer::MAX_LENGTH]],
            attributes: ['security_answer' => '答え'],
        );

        if (SecurityAnswer::normalize($this->security_answer) === '') {
            $this->addError('security_answer', '答えを入れてください。');

            return;
        }

        /** @var User $user */
        $user = Auth::user();

        $answers->set($user, $this->security_answer);

        $this->reset('security_answer');
        unset($this->trustedDeviceCount);

        Flux::toast(variant: 'success', text: '秘密の質問の答えを変えました。');
    }

    /**
     * 「この端末を覚える」を選んだ、期限内の端末の数。
     */
    #[Computed]
    public function trustedDeviceCount(): int
    {
        /** @var User $user */
        $user = Auth::user();

        return app(TrustedDevices::class)->count($user);
    }

    public function revokeTrustedDevices(TrustedDevices $devices): void
    {
        /** @var User $user */
        $user = Auth::user();

        $devices->revokeAll($user);

        unset($this->trustedDeviceCount);

        Flux::toast(variant: 'success', text: '信頼している端末をすべて解除しました。');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Security settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />
            <flux:input
                wire:model="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-password-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>

        <section class="mt-12" data-test="security-question">
            <flux:heading>秘密の質問を変える</flux:heading>
            <flux:subheading>新しい端末で入るときに聞きます。変えると、信頼している端末はすべて解除されます。</flux:subheading>

            @if (auth()->user()->hasSecurityAnswer())
                <form wire:submit="changeSecurityAnswer" class="mt-4 space-y-4">
                    <flux:input
                        wire:model="security_answer"
                        :label="\App\Services\SecurityAnswer::QUESTION"
                        :description="\App\Services\SecurityAnswer::HINT"
                        type="text"
                        maxlength="{{ \App\Services\SecurityAnswer::MAX_LENGTH }}"
                        required
                        autocomplete="off"
                    />
                    <flux:button variant="primary" type="submit" data-test="security-answer-change">答えを変える</flux:button>
                </form>
            @else
                <p class="mt-4"><flux:link :href="route('security-answer.setup')">答えを決める</flux:link></p>
            @endif
        </section>

        @if (auth()->user()->hasSecurityAnswer())
            <section class="mt-12" data-test="trusted-devices">
                <flux:heading>信頼している端末</flux:heading>
                <flux:subheading>「この端末を覚える」を選んだ端末は、30日のあいだ秘密の質問を聞きません。パスワードや秘密の質問を変えると、すべて解除されます。</flux:subheading>

                <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
                    <p>今は <span class="font-bold" data-test="trusted-device-count">{{ $this->trustedDeviceCount }}台</span></p>
                    @if ($this->trustedDeviceCount > 0)
                        <flux:button size="sm" variant="danger" wire:click="revokeTrustedDevices" wire:confirm="信頼している端末をすべて解除しますか？次に入るときは、どの端末でも秘密の質問を聞きます。" data-test="trusted-devices-revoke">
                            すべて解除
                        </flux:button>
                    @endif
                </div>
            </section>
        @endif

    </x-pages::settings.layout>

</section>
