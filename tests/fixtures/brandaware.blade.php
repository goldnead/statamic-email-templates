{{--
    Stand-in for a host-app mail shell that reads the brand, the way the addon
    playground's `mail.kampagne` does. It prints the handle it rendered under,
    so a test can say WHICH brand the preview ran as — the thing that was wrong
    on the demo on 03.09.2026, where a Chorwerkstatt template previewed with
    Nordlicht's identity and nothing on screen said so.
--}}
@php
    $marke = app()->bound('brand-context') ? app('brand-context')->current() : null;
@endphp
<!doctype html>
<html lang="de">
<head><title>{{ $title ?? 'Fallback Title' }}</title></head>
<body>
    <div class="brand-header">BRAND HEADER: {{ $marke->handle ?? 'keine' }}</div>
    <main>@yield('content')</main>
</body>
</html>
