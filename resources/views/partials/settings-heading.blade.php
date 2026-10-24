@php($homeMembership = auth()->user()?->homeMembership())
<div class="relative mb-6 w-full">
    <a href="{{ auth()->user()->homeUrl() }}" wire:navigate class="mb-4 inline-block text-body font-bold text-brand-ink underline" data-test="back-to-home">← {{ $homeMembership?->submit_only ? '送る画面' : 'ホーム' }}へ戻る</a>
    <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Manage your profile and account settings') }}</flux:subheading>
    <flux:separator variant="subtle" />
</div>
