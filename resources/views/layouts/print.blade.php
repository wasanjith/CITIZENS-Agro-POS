{{--
    Thermal print layout (80 mm / 58 mm). Rendered by Chrome and printed silently
    with --kiosk-printing; the Windows driver rasterises the page, so Sinhala prints correctly.
--}}
<!DOCTYPE html>
<html lang="{{ $htmlLang ?? 'si' }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Print')</title>
    @vite('resources/css/print.css')
    <style>
        :root {
            --paper-width: {{ $paperWidth ?? 80 }}mm;
            --content-width: {{ ($paperWidth ?? 80) - 8 }}mm;
        }
    </style>
</head>
<body class="thermal">
    <div class="sheet">
        @yield('content')
    </div>

    @if (! empty($autoprint))
        <script>
            // Wait for the Sinhala web font, otherwise the first print can come out blank.
            window.addEventListener('load', () => {
                document.fonts.ready.then(() => {
                    setTimeout(() => {
                        window.print();
                        window.parent?.postMessage({ type: 'citizens:printed' }, '*');
                    }, 150);
                });
            });
        </script>
    @endif
</body>
</html>
