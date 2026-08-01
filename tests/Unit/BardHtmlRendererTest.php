<?php

use Goldnead\EmailTemplates\Support\BardHtmlRenderer;
use Goldnead\EmailTemplates\Support\HtmlToBard;

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
