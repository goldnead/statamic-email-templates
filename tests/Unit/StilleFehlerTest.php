<?php

use Goldnead\EmailTemplates\Support\BardHtmlRenderer;
use Goldnead\EmailTemplates\Support\FunctionTags;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Illuminate\Support\Facades\Log;

/**
 * With a backtrack limit of 0 every preg_replace_callback() fails and returns null (PHP's
 * "Backtrack limit exhausted"). Runs $work with that limit and restores it after.
 */
$mitPcreGrenze = function (callable $work) {
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '0');

    try {
        return $work();
    } finally {
        ini_set('pcre.jit', (string) $jit);
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
};

it('keeps the text and logs when the merge-variable pass fails', function () use ($mitPcreGrenze) {
    Log::spy();
    $text = 'Hallo {{ name }}';

    $out = $mitPcreGrenze(fn () => MergeVariables::apply($text, ['name' => 'Anna']));

    expect($out)->toBe($text);
    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('keeps the text and logs when the function-tag pass fails', function () use ($mitPcreGrenze) {
    Log::spy();
    $text = 'Hallo {{ countdown until="2030-01-01" }}';

    $out = $mitPcreGrenze(fn () => FunctionTags::apply($text, []));

    expect($out)->toBe($text);
    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('keeps the HTML and logs when the link rewrite fails', function () use ($mitPcreGrenze) {
    Log::spy();
    $html = '<a'.str_repeat(' x', 3000).' href="https://example.test/">Hallo</a>';
    $method = new ReflectionMethod(BardHtmlRenderer::class, 'rewriteAttribute');
    $method->setAccessible(true);

    $out = $mitPcreGrenze(fn () => $method->invoke(new BardHtmlRenderer, $html, 'a', 'href'));

    expect($out)->toBe($html);
    Log::shouldHaveReceived('error')->atLeast()->once();
});
