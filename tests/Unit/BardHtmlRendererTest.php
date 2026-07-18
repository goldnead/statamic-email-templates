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
