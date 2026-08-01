<?php

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Services\EmailTemplateResolver;
use Goldnead\EmailTemplates\Support\EmailTemplateBlueprint;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Illuminate\Support\Facades\Artisan;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

// The collections/entries Stache is redirected to a per-process temp dir that
// persists across tests in a run, so clear any leftover entries up front.
beforeEach(function () {
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)?->queryEntries()->get()->each->delete();
});

// `fakeTemplateSource()` and `registerFakeSource()` live in tests/Pest.php.

// -- Slice 1: collection + blueprint + entry CRUD -------------------------

it('registers the et_templates collection and its blueprint', function () {
    expect(Collection::findByHandle(EmailTemplateCollectionManager::HANDLE))->not->toBeNull();

    $blueprint = Blueprint::find(
        EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE
    );

    expect($blueprint)->not->toBeNull()
        ->and($blueprint->hasField('subject'))->toBeTrue()
        ->and($blueprint->hasField('body'))->toBeTrue()
        ->and($blueprint->field('body')->type())->toBe('bard');
});

it('creates and reads a template entry by slug', function () {
    $manager = app(EmailTemplateCollectionManager::class);

    [$entry, $created] = $manager->upsert(new EmailTemplateData(
        slug: 'welcome',
        title: 'Welcome',
        subject: 'Willkommen, {{ contact.first_name }}',
        body: '<h1>Hallo</h1>',
    ));

    expect($created)->toBeTrue()
        ->and($entry->slug())->toBe('welcome');

    $found = $manager->findBySlug('welcome');

    expect($found)->not->toBeNull()
        ->and($found->value('subject'))->toBe('Willkommen, {{ contact.first_name }}')
        // Bard stores ProseMirror nodes, not raw HTML.
        ->and($found->value('body'))->toBeArray();
});

// -- Slice 2: import command ---------------------------------------------

it('imports file-based templates into entries, preserving the slug', function () {
    registerFakeSource(fakeTemplateSource([
        ['handle' => 'newsletter', 'name' => 'Newsletter', 'html' => '<p>Body</p>'],
    ]));

    $code = Artisan::call('email-templates:import');

    expect($code)->toBe(0);

    $entry = app(EmailTemplateCollectionManager::class)->findBySlug('newsletter');

    expect($entry)->not->toBeNull()
        ->and($entry->slug())->toBe('newsletter')
        ->and($entry->value('title'))->toBe('Newsletter');

    // Body round-trips through Bard: resolver renders it back to HTML.
    $resolved = app(EmailTemplateResolver::class)->resolve('newsletter');
    expect($resolved->body)->toContain('Body');
});

it('skips existing slugs unless --overwrite is passed', function () {
    $manager = app(EmailTemplateCollectionManager::class);
    $manager->upsert(new EmailTemplateData(slug: 'promo', title: 'Old', body: '<p>OLD</p>'));

    registerFakeSource(fakeTemplateSource([
        ['slug' => 'promo', 'title' => 'New', 'body' => '<p>NEW</p>'],
    ]));

    Artisan::call('email-templates:import');
    expect(app(EmailTemplateResolver::class)->resolve('promo')->body)->toContain('OLD');

    Artisan::call('email-templates:import', ['--overwrite' => true]);
    expect(app(EmailTemplateResolver::class)->resolve('promo')->body)->toContain('NEW');
});

it('reports nothing to import when no source yields templates', function () {
    $code = Artisan::call('email-templates:import');

    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('No file-based templates found');
});

// -- Slice 3: resolver with fallback -------------------------------------

it('resolves the entry template over the file fallback (entry wins)', function () {
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'reminder',
        title: 'Reminder',
        subject: 'From entry',
        body: '<p>ENTRY BODY</p>',
    ));

    $fallbackCalled = false;
    $resolved = app(EmailTemplateResolver::class)->resolve('reminder', function () use (&$fallbackCalled) {
        $fallbackCalled = true;

        return ['title' => 'File', 'body' => 'FILE BODY'];
    });

    expect($fallbackCalled)->toBeFalse()
        ->and($resolved)->not->toBeNull()
        ->and($resolved->source)->toBe('entry')
        ->and($resolved->body)->toContain('ENTRY BODY');
});

it('falls back to the file template when no entry exists', function () {
    $resolved = app(EmailTemplateResolver::class)->resolve('missing', fn () => [
        'title' => 'File',
        'body' => 'FILE BODY',
    ]);

    expect($resolved)->not->toBeNull()
        ->and($resolved->source)->toBe('fallback')
        ->and($resolved->slug)->toBe('missing')
        ->and($resolved->body)->toBe('FILE BODY');
});

it('returns null when neither an entry nor a fallback yields a template', function () {
    expect(app(EmailTemplateResolver::class)->resolve('nope'))->toBeNull();
    expect(app(EmailTemplateResolver::class)->resolve('nope', fn () => null))->toBeNull();
});

it('exposes resolve through the EmailTemplates facade', function () {
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'facade',
        title: 'Facade',
        body: '<p>VIA FACADE</p>',
    ));

    $result = EmailTemplates::resolve('facade');

    expect($result)->not->toBeNull()
        ->and($result->slug)->toBe('facade')
        ->and($result->body)->toContain('VIA FACADE')
        ->and($result->source)->toBe('entry');
});
