<x-layouts::auth title="ログインしたままです">
    <div class="flex flex-col gap-6" data-test="reset-while-logged-in">
        <x-auth-header title="ログインしたままです" :description="'今は「'.$currentUser->name.'」さん（'.$currentUser->email.'）でログインしています。パスワードを決めるには、いったんログアウトします。'" />

        <form method="POST" action="{{ route('password.reset.switch') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">
            <flux:button variant="primary" type="submit" class="w-full !h-14 !text-lg">ログアウトして続ける</flux:button>
        </form>

        <flux:link class="text-center text-body" :href="route('dashboard')">やめて、このまま使う</flux:link>
    </div>
</x-layouts::auth>
