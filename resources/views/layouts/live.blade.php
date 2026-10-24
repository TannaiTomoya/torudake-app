<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <script>
            (() => {
                const fit = () => document.documentElement.style.setProperty('--live-scale', Math.min(window.innerWidth / 1920, window.innerHeight / 1080));
                fit();
                window.addEventListener('resize', fit);
            })();
        </script>
    </head>
    <body class="fixed inset-0 overflow-hidden bg-paper font-sans text-ink antialiased">
        <div class="live-stage" data-test="live-stage">
            {{ $slot }}
        </div>

        @fluxScripts
    </body>
</html>
