<?php

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkey;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Attributes\On;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    public bool $canManagePasskeys;


    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManagePasskeys = Features::canManagePasskeys();
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

    }

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
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }

    /**
     * 顔でログインに登録した端末（自分のものだけ）。
     *
     * @return Collection<int, Passkey>
     */
    #[Computed]
    public function passkeys(): Collection
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->passkeys()->latest()->get();
    }

    public function deletePasskey(int $passkeyId): void
    {
        /** @var User $user */
        $user = Auth::user();

        $user->passkeys()->findOrFail($passkeyId)->delete();

        unset($this->passkeys);

        Flux::toast(variant: 'success', text: '登録を消しました。');
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

        @if ($canManageTwoFactor)
            <section class="mt-12">
                <flux:heading>{{ __('Two-factor authentication') }}</flux:heading>
                <flux:subheading>{{ __('Manage your two-factor authentication settings') }}</flux:subheading>

                <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="space-y-4">
                            <flux:text>
                                {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                            </flux:text>

                            <div class="flex justify-start">
                                <flux:button
                                    variant="danger"
                                    wire:click="disable"
                                >
                                    {{ __('Disable 2FA') }}
                                </flux:button>
                            </div>

                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                        </div>
                    @else
                        <div class="space-y-4">
                            <flux:text variant="subtle">
                                {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                            </flux:text>

                            <flux:modal.trigger name="two-factor-setup-modal">
                                <flux:button
                                    variant="primary"
                                    wire:click="$dispatch('start-two-factor-setup')"
                                >
                                    {{ __('Enable 2FA') }}
                                </flux:button>
                            </flux:modal.trigger>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if ($canManagePasskeys)
            <section class="mt-12" data-test="passkeys">
                <flux:heading>顔でログイン</flux:heading>
                <flux:subheading>このスマホ（パソコン）を登録すると、次からログイン画面の「顔でログイン」を押して、顔を見せるだけで入れます。</flux:subheading>

                <div class="mt-4 space-y-4 text-sm" x-data="passkeyRegister">
                    @if ($this->passkeys->isNotEmpty())
                        <ul class="divide-y divide-line rounded-lg border border-line">
                            @foreach ($this->passkeys as $passkey)
                                <li class="flex items-center justify-between gap-3 p-3" wire:key="passkey-{{ $passkey->id }}">
                                    <div>
                                        <p class="font-bold">{{ $passkey->name }}</p>
                                        <p class="text-ink-sub">
                                            {{ $passkey->created_at?->isoFormat('M月D日(ddd)') }}に登録・最後に使った日 {{ $passkey->last_used_at?->isoFormat('M月D日(ddd)') ?? 'まだ使っていません' }}
                                        </p>
                                    </div>
                                    <flux:button size="sm" variant="danger" wire:click="deletePasskey({{ $passkey->id }})" wire:confirm="「{{ $passkey->name }}」の登録を消しますか？" data-test="passkey-delete">
                                        消す
                                    </flux:button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div x-show="supported" class="flex flex-wrap items-end gap-3">
                        <flux:input x-model="name" label="端末の名前" placeholder="例：父のiPhone" class="max-w-xs" />
                        <flux:button variant="primary" icon="face-smile" x-on:click="register()" x-bind:disabled="busy" data-test="passkey-register">
                            この端末を登録する
                        </flux:button>
                    </div>
                    <p x-show="!supported" x-cloak class="text-ink-sub">このブラウザは顔でログインに対応していません。</p>
                    <p x-show="error" x-text="error" x-cloak class="font-bold text-ng" role="alert"></p>
                </div>
            </section>
        @endif

    </x-pages::settings.layout>

</section>
