@php
    $navUser = auth()->user();
    $navCompany = request()->route('company');
    if (! $navCompany instanceof \App\Models\Company) {
        $navCompany = $navUser?->homeMembership()?->company;
    }
    $navMembership = $navCompany !== null ? $navUser?->activeMembershipFor($navCompany) : null;
    $submitOnly = $navMembership?->submit_only === true;
    $businessReady = $navUser?->hasVerifiedEmail() && $navUser->two_factor_confirmed_at !== null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @unless ($submitOnly)
        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900" data-test="sidebar">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse tooltip="サイドバーを畳む・開く" />
            </flux:sidebar.header>

            @if ($navMembership !== null)
                <flux:sidebar.nav class="[&_[data-content]]:text-body">
                    <flux:sidebar.item icon="home" :href="route('company.home', $navCompany)" :current="request()->routeIs('company.home')" wire:navigate data-test="sidebar-home">
                        ホーム
                    </flux:sidebar.item>

                    @if ($businessReady)
                    @can('viewOwner', $navCompany)
                        @php($concernCount = \App\Support\OwnerConcerns::count($navCompany))
                        <flux:sidebar.item icon="chart-bar" :icon-dot="$concernCount > 0" :href="route('company.owner', $navCompany)" :current="request()->routeIs('company.owner')" wire:navigate data-test="sidebar-owner">
                            社長の画面
                            <span x-data="{ count: {{ $concernCount }} }" x-on:concerns-changed.window="count = $event.detail.count" x-show="count > 0" x-text="count"
                                  @if ($concernCount === 0) style="display: none" @endif
                                  class="ms-2 rounded-full bg-warn-soft px-2 text-note font-bold text-warn" aria-label="まだ見ていない気になる仕訳の数" data-test="sidebar-concern-count">{{ $concernCount }}</span>
                        </flux:sidebar.item>
                    @else
                        @can('editDraft', $navCompany)
                            @php($remaining = \App\Support\RemainingCount::for($navCompany))
                            <flux:sidebar.item icon="pencil-square" :icon-dot="$remaining > 0" :href="route('company.sources.index', $navCompany)" :current="request()->routeIs('company.sources.*')" wire:navigate data-test="sidebar-sources">
                                直して保存
                                @if ($remaining > 0)
                                    <span class="ms-2 rounded-full bg-brand-soft px-2 text-note font-bold text-brand-ink" aria-label="今月あと何件" data-test="sidebar-remaining">あと{{ $remaining }}件</span>
                                @endif
                            </flux:sidebar.item>
                        @endcan
                        @can('submit', $navCompany)
                            <flux:sidebar.item icon="camera" :href="route('company.submit', $navCompany)" :current="request()->routeIs('company.submit')" wire:navigate data-test="sidebar-submit">
                                レシートを送る
                            </flux:sidebar.item>
                        @endcan
                    @endcan

                    @can('viewSources', $navCompany)
                        <flux:sidebar.item icon="book-open" :href="route('company.entries.index', $navCompany)" :current="request()->routeIs('company.entries.*')" wire:navigate data-test="sidebar-entries">
                            仕訳の一覧
                        </flux:sidebar.item>
                    @endcan
                    @can('exportExcel', $navCompany)
                        <flux:sidebar.item icon="table-cells" :href="route('company.workbook', $navCompany)" data-test="sidebar-workbook">
                            Excel
                        </flux:sidebar.item>
                    @endcan
                    @can('viewLive', $navCompany)
                        <flux:sidebar.item icon="tv" :href="route('company.live', $navCompany)" data-test="sidebar-live">
                            ライブ画面
                        </flux:sidebar.item>
                    @endcan
                    @endif
                </flux:sidebar.nav>
            @endif

            <flux:spacer />

            <flux:sidebar.nav class="[&_[data-content]]:text-body">
                @if ($businessReady && $navMembership !== null && $navUser->can('manageSettings', $navCompany))
                    <flux:sidebar.item icon="cog-6-tooth" :href="route('company.settings', $navCompany)" :current="request()->routeIs('company.settings')" wire:navigate data-test="sidebar-settings">
                        設定
                    </flux:sidebar.item>
                @else
                    <flux:sidebar.item icon="cog-6-tooth" :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate data-test="sidebar-settings">
                        設定
                    </flux:sidebar.item>
                @endif
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            設定
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            ログアウト
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>
        @endunless

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
