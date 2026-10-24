{{-- 2段階認証がまだの人に、ホーム・送る画面で出す唯一のカード --}}
<div class="space-y-4 rounded-2xl border-2 border-brand bg-brand-soft p-6" data-test="two-factor-first">
    <p class="text-note font-bold text-brand-ink">最初にやること（1回だけ・1分）</p>
    <p class="text-2xl font-bold text-ink">2段階認証を設定する</p>
    <p class="text-ink-sub">スマホの認証アプリ（Google Authenticator など）に鍵を入れ、出てきた6桁の数字を入れます。終わると復旧コードが出るので、紙に書いて保管してください。</p>
    <flux:modal.trigger name="two-factor-setup-modal">
        <button type="button" wire:click="$dispatch('start-two-factor-setup')" class="rounded-xl bg-ink px-6 py-3 text-lg font-bold text-paper" data-test="two-factor-start">設定をはじめる</button>
    </flux:modal.trigger>
    <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="true" />
</div>
