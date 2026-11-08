<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')">
        <p class="text-body text-ink" data-test="appearance-fixed">{{ __('This app is always shown in light mode. It does not follow the dark mode of your device.') }}</p>
    </x-pages::settings.layout>
</section>
