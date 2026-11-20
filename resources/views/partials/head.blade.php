<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

{{-- アプリの絵（タブ・スマホのホーム画面・PC のショートカット）。元の絵は resources/images/app-icon.png。
     絵を替えたら ?v= の数字を上げる（ブラウザは古い絵を長く覚えているため） --}}
<link rel="icon" href="/favicon.ico?v=2" sizes="48x48">
<link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png?v=2">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
<link rel="manifest" href="/app-manifest.json?v=2">

@fonts

{{-- 常にライト表示（#10）。配色のトークンはライト前提。以前の設定が残っていても dark を付けない --}}
<meta name="color-scheme" content="light">
<script>
    document.documentElement.classList.remove('dark');
    try { window.localStorage.removeItem('flux.appearance'); } catch (e) {}
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
