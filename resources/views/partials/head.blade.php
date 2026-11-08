<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

{{-- 常にライト表示（#10）。配色のトークンはライト前提。以前の設定が残っていても dark を付けない --}}
<meta name="color-scheme" content="light">
<script>
    document.documentElement.classList.remove('dark');
    try { window.localStorage.removeItem('flux.appearance'); } catch (e) {}
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
