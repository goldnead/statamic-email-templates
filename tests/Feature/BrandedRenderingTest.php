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

it('wraps the send body in the per-entry chosen layout', function () {
    // Map a `transactional` handle to its own shell fixture.
    config()->set('email-templates.layouts', [
        'transactional' => 'testbrand::transactional',
    ]);

    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'receipt',
        title: 'Receipt',
        subject: 'Deine Quittung',
        body: '<p>Danke für deinen Kauf.</p>',
        layout: 'transactional',
    ));

    $resolved = app(EmailTemplateResolver::class)->resolve('receipt');

    expect($resolved)->not->toBeNull();
    expect($resolved->layout)->toBe('transactional');
    // Wrapped in the transactional shell, NOT the default branded shell.
    expect($resolved->body)
        ->toContain('TRANSACTIONAL HEADER')
        ->toContain('Danke für deinen Kauf.')
        ->toContain('TRANSACTIONAL FOOTER')
        ->not->toContain('BRAND HEADER');
});

it('falls back to the branded layout when an entry picks no layout', function () {
    config()->set('email-templates.layouts', [
        'transactional' => 'testbrand::transactional',
    ]);

    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'welcome',
        title: 'Welcome',
        subject: 'Willkommen',
        body: '<p>Schön dass du da bist.</p>',
        // no layout chosen
    ));

    $resolved = app(EmailTemplateResolver::class)->resolve('welcome');

    expect($resolved)->not->toBeNull();
    expect($resolved->layout)->toBeNull();
    // Historic behaviour unchanged: the single branded_layout wraps it.
    expect($resolved->body)
        ->toContain('BRAND HEADER')
        ->toContain('Schön dass du da bist.')
        ->toContain('BRAND FOOTER')
        ->not->toContain('TRANSACTIONAL HEADER');
});

it('falls back to the branded layout for an unknown layout handle', function () {
    config()->set('email-templates.layouts', [
        'transactional' => 'testbrand::transactional',
    ]);

    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'newsletter',
        title: 'Newsletter',
        subject: 'Neuigkeiten',
        body: '<p>Frische Neuigkeiten.</p>',
        layout: 'does-not-exist',
    ));

    $resolved = app(EmailTemplateResolver::class)->resolve('newsletter');

    expect($resolved)->not->toBeNull();
    // Unknown handle → branded_layout fallback, nothing breaks.
    expect($resolved->body)
        ->toContain('BRAND HEADER')
        ->toContain('Frische Neuigkeiten.')
        ->toContain('BRAND FOOTER')
        ->not->toContain('TRANSACTIONAL HEADER');
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

/** A stand-in brand-context that can switch and remembers where it was. */
function fakeSwitchingBrandContext(string $current): void
{
    app()->instance('brand-context', new class($current)
    {
        public function __construct(private string $current) {}

        public function multiBrandEnabled(): bool
        {
            return true;
        }

        public function hasCurrent(): bool
        {
            return true;
        }

        public function current(): object
        {
            return (object) ['handle' => $this->current, 'name' => $this->current];
        }

        public function default(): object
        {
            return (object) ['handle' => $this->current, 'name' => $this->current];
        }

        public function runFor(string $brand, Closure $callback): mixed
        {
            $previous = $this->current;
            $this->current = $brand;

            try {
                return $callback();
            } finally {
                $this->current = $previous;
            }
        }
    });
}

/**
 * The regression the brand switch exists for.
 *
 * Before the fix the preview rendered under the brand the REQUEST resolved to,
 * which in the Control Panel is the logged-in editor's. Opening a Chorwerkstatt
 * template as a Nordlicht user therefore wrapped Chorwerkstatt's words in
 * Nordlicht's shell, with nothing on screen saying so — the demo showed exactly
 * that on 03.09.2026.
 *
 * The shell fixture prints the handle it rendered under, so this names the
 * brand instead of inferring it from a colour. Against the old controller it
 * reports `nordlicht` and fails.
 */
it('previews a template under its own brand, not the editor\'s', function () {
    config()->set('email-templates.branded_layout', 'testbrand::brandaware');

    fakeSwitchingBrandContext('nordlicht');

    [$entry] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'cw-willkommen',
        title: 'Willkommen in der Chorwerkstatt',
        subject: 'Hallo {{ contact.first_name }}',
        body: '<p>Schön dass du da bist.</p>',
    ));

    // The manager stamps the current brand; this template belongs to the other.
    $entry->set('brand', ['chorwerkstatt'])->saveQuietly();

    LivePreview::tokenize('lp-brand-token', $entry);

    $response = $this->get('/'.EmailTemplateCollectionManager::LIVE_PREVIEW_ROUTE.'?token=lp-brand-token');

    $response->assertOk();

    expect($response->getContent())
        ->toContain('BRAND HEADER: chorwerkstatt')
        ->not->toContain('BRAND HEADER: nordlicht');
});

it('leaves the editor\'s brand where it was afterwards', function () {
    config()->set('email-templates.branded_layout', 'testbrand::brandaware');

    fakeSwitchingBrandContext('nordlicht');

    [$entry] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'cw-willkommen',
        title: 'Willkommen',
        subject: 'Hallo',
        body: '<p>Text.</p>',
    ));

    $entry->set('brand', ['chorwerkstatt'])->saveQuietly();

    LivePreview::tokenize('lp-restore-token', $entry);

    $this->get('/'.EmailTemplateCollectionManager::LIVE_PREVIEW_ROUTE.'?token=lp-restore-token')->assertOk();

    expect(app('brand-context')->current()->handle)->toBe('nordlicht');
});
