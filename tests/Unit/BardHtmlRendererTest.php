<?php

use Goldnead\EmailTemplates\Support\BardHtmlRenderer;
use Goldnead\EmailTemplates\Support\HtmlToBard;
use Illuminate\Support\Facades\URL;

/**
 * Images, both directions (2.5.0).
 *
 * Until 2.4.0 a picture was lost twice over: `HtmlToBard` parsed HTML with a
 * tiptap schema that has no `image` node, so `<img>` never became a node, and
 * `BardHtmlRenderer` rendered an `image` node it was handed as the empty
 * string. Neither said anything. The two now share one extension list
 * (`TiptapExtensions`), so a node added for one direction is added for both.
 */
it('renders an image node instead of swallowing it', function () {
    $nodes = [['type' => 'image', 'attrs' => ['src' => 'https://example.com/flyer.png', 'alt' => 'Flyer']]];

    expect((new BardHtmlRenderer)->render($nodes))
        ->toContain('<img')
        ->toContain('https://example.com/flyer.png')
        ->toContain('Flyer');
});

it('keeps an image when HTML is converted to Bard', function () {
    $nodes = (new HtmlToBard)->convert('<p><img src="https://example.com/flyer.png" alt="Flyer"></p>');

    $types = collect($nodes)->flatMap(fn ($n) => collect($n['content'] ?? [])->pluck('type'))->all();

    expect($types)->toContain('image');
});

it('round-trips an image through Bard and back', function () {
    $html = (new BardHtmlRenderer)->render(
        (new HtmlToBard)->convert('<p>Vor <img src="https://example.com/flyer.png" alt="Flyer"> nach</p>')
    );

    expect($html)
        ->toContain('https://example.com/flyer.png')
        ->toContain('Vor')
        ->toContain('nach');
});

it('gives every image the inline styles a mail client needs', function () {
    // Not decoration: a 1200px header graphic without max-width forces a
    // sideways scroll in a phone client that ignores the viewport.
    $html = (new BardHtmlRenderer)->render(
        [['type' => 'image', 'attrs' => ['src' => 'https://example.com/flyer.png']]]
    );

    expect($html)
        ->toContain('max-width:100%')
        ->toContain('height:auto')
        // In CSS, not as border="0" — tiptap-php's renderAttributes() runs the
        // attribute array through array_filter(), and '0' is falsy in PHP.
        ->toContain('border:0');
});

it('makes a relative image source absolute, in nodes and in raw HTML alike', function () {
    URL::forceScheme('https');
    URL::forceRootUrl('https://nordlicht.example');

    $renderer = new BardHtmlRenderer;

    $fromNodes = $renderer->render([['type' => 'image', 'attrs' => ['src' => '/assets/flyer.png']]]);
    // The raw-string path carries imported legacy templates, which hold the
    // same relative paths and would break the same way.
    $fromString = $renderer->render('<p><img src="/assets/flyer.png" alt="Flyer"></p>');

    expect($fromNodes)->toContain('https://nordlicht.example/assets/flyer.png')
        ->and($fromString)->toContain('https://nordlicht.example/assets/flyer.png');
});

it('leaves an absolute, protocol-relative, data or cid image source alone', function () {
    URL::forceScheme('https');
    URL::forceRootUrl('https://nordlicht.example');

    $renderer = new BardHtmlRenderer;

    // Rewriting any of these would break an embedded or attached image.
    expect($renderer->render('<img src="https://cdn.example/flyer.png">'))
        ->toContain('src="https://cdn.example/flyer.png"')
        ->and($renderer->render('<img src="//cdn.example/flyer.png">'))
        ->toContain('src="//cdn.example/flyer.png"')
        ->and($renderer->render('<img src="cid:flyer">'))
        ->toContain('src="cid:flyer"')
        ->and($renderer->render('<img src="data:image/gif;base64,R0lGOD">'))
        ->toContain('src="data:image/gif;base64,R0lGOD"');
});

it('renders ProseMirror nodes to HTML', function () {
    $nodes = [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'Hello world']],
    ]];

    $html = (new BardHtmlRenderer)->render($nodes);

    expect($html)->toContain('Hello world')
        ->and($html)->toContain('<p>');
});

it('passes an HTML string through untouched', function () {
    $html = '<h1>Already HTML</h1>';

    expect((new BardHtmlRenderer)->render($html))->toBe($html);
});

it('returns an empty string for null or empty values', function () {
    $renderer = new BardHtmlRenderer;

    expect($renderer->render(null))->toBe('')
        ->and($renderer->render([]))->toBe('')
        ->and($renderer->render(''))->toBe('');
});

it('round-trips HTML through Bard and back to HTML', function () {
    $original = '<h2>Heading</h2><p>A <strong>bold</strong> paragraph.</p>';

    $nodes = (new HtmlToBard)->convert($original);
    expect($nodes)->toBeArray()->not->toBeEmpty();

    $html = (new BardHtmlRenderer)->render($nodes);

    expect($html)->toContain('Heading')
        ->and($html)->toContain('bold')
        ->and($html)->toContain('paragraph');
});

it('converts plain text to a single Bard paragraph', function () {
    $nodes = (new HtmlToBard)->convert('Just text');

    expect($nodes)->toBeArray()->not->toBeEmpty();

    $html = (new BardHtmlRenderer)->render($nodes);
    expect($html)->toContain('Just text');
});

// -- The trust boundary ---------------------------------------------------
//
// `resources/views/branded.blade.php` emits the rendered body with `{!! !!}`,
// and the Live Preview iframe drops it into the CP unescaped. That is only
// defensible if the Bard -> HTML path cannot produce active markup. These
// tests pin that boundary; a change in tiptap's schema handling must fail here
// rather than in someone's inbox.

it('does not emit a script node authored in Bard content', function () {
    $nodes = [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'harmlos']]],
        ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
    ];

    $html = (new BardHtmlRenderer)->render($nodes);

    expect($html)->toContain('harmlos')
        ->and($html)->not->toContain('<script');
});

it('escapes markup that arrives as Bard text', function () {
    $nodes = [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => '<script>alert(1)</script>']],
    ]];

    $html = (new BardHtmlRenderer)->render($nodes);

    expect($html)->not->toContain('<script')
        ->and($html)->toContain('&lt;script&gt;');
});

it('drops an event-handler attribute smuggled onto a node', function () {
    $nodes = [[
        'type' => 'paragraph',
        'attrs' => ['onclick' => 'alert(1)'],
        'content' => [['type' => 'text', 'text' => 'klick']],
    ]];

    $html = (new BardHtmlRenderer)->render($nodes);

    expect($html)->toContain('klick')
        ->and($html)->not->toContain('onclick');
});

it('strips script tags when importing raw HTML into Bard', function () {
    $nodes = (new HtmlToBard)->convert('<p>ok</p><script>alert(1)</script>');

    $html = (new BardHtmlRenderer)->render($nodes);

    expect($html)->toContain('ok')
        ->and($html)->not->toContain('alert(1)');
});

it('passes a pre-rendered HTML string through unfiltered — the caller owns it', function () {
    // Documented escape hatch for `save_html: true` Bard configs and imported
    // raw HTML. Whoever puts an HTML string into the body field is trusted; the
    // addon's own blueprint sets `save_html: false`, so the normal path is nodes.
    $html = '<p>ok</p><script>alert(1)</script>';

    expect((new BardHtmlRenderer)->render($html))->toBe($html);
});
