<x-layouts::auth title="秘密の質問">
    <div class="flex flex-col gap-6">
        <x-auth-header title="秘密の質問" description="この端末ではじめて入るとき（または30日ぶり）は、答えを聞きます。" />

        <form method="POST" action="{{ route('security-question.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="answer"
                :label="\App\Services\SecurityAnswer::QUESTION"
                :value="old('answer')"
                type="text"
                maxlength="{{ \App\Services\SecurityAnswer::MAX_LENGTH }}"
                required
                autofocus
                autocomplete="off"
            />

            <flux:checkbox name="trust_device" value="1" :checked="true" label="この端末を覚える（30日は質問しません）" data-test="trust-device" />

            <flux:button variant="primary" type="submit" class="w-full !h-14 !text-lg" data-test="security-question-button">
                入る
            </flux:button>
        </form>

        <p class="text-center text-body text-ink-sub">答えを忘れたときは、管理者に連絡してください。</p>
        <flux:link class="text-center text-body" :href="route('login')">ログイン画面に戻る</flux:link>
    </div>
</x-layouts::auth>
