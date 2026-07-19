<!doctype html>
<html lang="de">
<head><title>{{ $title ?? 'Fallback Title' }}</title></head>
<body>
    <div class="tx-header">TRANSACTIONAL HEADER</div>
    <main>@yield('content')</main>
    <div class="tx-footer">TRANSACTIONAL FOOTER</div>
</body>
</html>
