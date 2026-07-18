<?php

use Facades\Statamic\CP\LivePreview;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Services\EmailTemplateResolver;
use Goldnead\EmailTemplates\Support\BrandedBodyRenderer;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Illuminate\Support\Facades\View;
use Statamic\Facades\Collection;

/**
 * Branded rendering: when `email-templates.branded_layout` points at a host-app
 * layout, the rendered template body is wrapped in that shell. This covers BOTH
 * the resolver (the send path used by the automations send action) and the CP
 * Live Preview route. A stub layout stands in for the host app's `emails.layout`.
 */
beforeEach(function () {
    // Clear only leftover entries — keep the ensured et_templates collection.
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)?->queryEntries()->get()->each->delete();

    // Register a stub branded layout ("emails.layout" stand-in) and switch on
    // branding for these tests.
    View::addNamespace('testbrand', __DIR__.'/../fixtures');
    config()->set('email-templates.branded_layout', 'testbrand::layout');
});

it('is enabled only when a branded layout is configured and exists', function () {
    expect(BrandedBodyRenderer::enabled())->toBeTrue();

    config()->set('email-templates.branded_layout', null);
    expect(BrandedBodyRenderer::enabled())->toBeFalse();

    config()->set('email-templates.branded_layout', 'testbrand::does-not-exist');
    expect(BrandedBodyRenderer::enabled())->toBeFalse();
});

it('returns the body unchanged when no branded layout is configured', function () {
    config()->set('email-templates.branded_layout', null);

    expect(BrandedBodyRenderer::wrap('<p>Hallo</p>', 'Betreff'))->toBe('<p>Hallo</p>');
});

it('wraps the resolved send body in the branded shell', function () {
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'welcome',
        title: 'Welcome',
        subject: 'Willkommen',
        body: '<p>Schön dass du da bist.</p>',
    ));

    $resolved = app(EmailTemplateResolver::class)->resolve('welcome');

    expect($resolved)->not->toBeNull();
    // Shell markup surrounds the original body content.
    expect($resolved->body)
        ->toContain('BRAND HEADER')
        ->toContain('Schön dass du da bist.')
        ->toContain('BRAND FOOTER')
        // Subject is forwarded into the layout <title>.
        ->toContain('<title>Willkommen</title>');
});

it('also brands the inline fallback body when a slug does not resolve', function () {
    $resolved = app(EmailTemplateResolver::class)->resolve(
        'missing-slug',
        fn () => ['html' => '<p>Inline Fallback</p>', 'subject' => 'Fallback-Betreff'],
    );

    expect($resolved)->not->toBeNull();
    expect($resolved->body)
        ->toContain('BRAND HEADER')
        ->toContain('Inline Fallback')
        ->toContain('BRAND FOOTER');
});

it('renders the branded shell in the live preview when configured', function () {
    [$entry] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'welcome',
        title: 'Welcome',
        subject: 'Hallo {{ contact.first_name }}',
        body: '<p>Schön dass du da bist, {{ contact.first_name }}.</p>',
    ));

    LivePreview::tokenize('lp-token', $entry);

    $response = $this->get('/'.EmailTemplateCollectionManager::LIVE_PREVIEW_ROUTE.'?token=lp-token');

    $response->assertOk();

    expect($response->getContent())
        ->toContain('BRAND HEADER')
        ->toContain('Schön dass du da bist, Maria')
        ->toContain('BRAND FOOTER')
        // The lean generic-preview document is bypassed when branding is on.
        ->not->toContain('class="et-body"');
});
