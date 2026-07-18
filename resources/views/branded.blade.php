{{--
    Branded email wrapper.

    Extends the host app's configured branded layout ($layout, e.g. `emails.layout`)
    and drops the already-rendered email body ($body) into its `@yield('content')`
    section. The subject is forwarded as `$title`, which the host layout uses for
    the document <title>. Rendered by BrandedBodyRenderer::wrap().

    $body is trusted, pre-rendered email HTML (Bard -> HTML + merge variables),
    so it is emitted unescaped.
--}}
@extends($layout, ['title' => $subject])

@section('content')
{!! $body !!}
@endsection
