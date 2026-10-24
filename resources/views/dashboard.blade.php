<x-layouts::app title="ホーム">
    <section class="mx-auto max-w-2xl space-y-4 p-4 text-body text-ink">
        <h1 class="text-2xl font-bold">会社への所属がありません</h1>
        <p class="rounded-xl border border-line bg-panel p-6">会社の管理者に、メンバーの登録をご依頼ください。登録が済んだら、ホームを開き直してください。</p>
        <nav aria-label="次にできること" class="flex flex-wrap gap-4 font-bold text-brand-ink underline">
            <a href="{{ route('dashboard') }}">ホームを開き直す</a>
            <a href="{{ route('profile.edit') }}" wire:navigate>個人設定</a>
        </nav>
    </section>
</x-layouts::app>
