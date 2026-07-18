<!doctype html>
<html lang="de">
<head><title>{{ $title ?? 'Fallback Title' }}</title></head>
<body>
    <div class="brand-header">BRAND HEADER</div>
    <main>@yield('content')</main>
    <div class="brand-footer">BRAND FOOTER</div>
</body>
</html>
