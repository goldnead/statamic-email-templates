<?php

use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Illuminate\Support\Facades\Artisan;

/**
 * `email-templates:import` is the documented migration path off file-based
 * templates. Its options decide whether a run writes into the site's content
 * directory at all, so each one is covered here.
 *
 * The happy path (import, slug preservation, --overwrite) lives in
 * EmailTemplatesTest; this file covers the remaining branches.
 */

it('is registered with the artisan console', function () {
    expect(array_key_exists('email-templates:import', Artisan::all()))->toBeTrue();
});

it('writes nothing on a dry run but reports what it would do', function () {
    registerFakeSource(fakeTemplateSource([
        ['handle' => 'newsletter', 'name' => 'Newsletter', 'html' => '<p>Body</p>'],
    ]));

    expect(Artisan::call('email-templates:import', ['--dry-run' => true]))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('create')
        ->and($output)->toContain('newsletter')
        ->and($output)->toContain('dry-run')
        ->and(app(EmailTemplateCollectionManager::class)->findBySlug('newsletter'))->toBeNull();
});

it('reports a dry-run overwrite as an update, not a create', function () {
    app(EmailTemplateCollectionManager::class)->upsert(
        new EmailTemplateData(slug: 'promo', title: 'Alt', body: '<p>ALT</p>')
    );

    registerFakeSource(fakeTemplateSource([
        ['slug' => 'promo', 'title' => 'Neu', 'body' => '<p>NEU</p>'],
    ]));

    Artisan::call('email-templates:import', ['--dry-run' => true, '--overwrite' => true]);

    expect(Artisan::output())->toContain('update')
        ->and(app(EmailTemplateCollectionManager::class)->findBySlug('promo')->value('title'))->toBe('Alt');
});

it('imports only the named source when --source is given', function () {
    registerFakeSource(
        fakeTemplateSource([['slug' => 'aus-a', 'title' => 'A', 'body' => '<p>A</p>']], 'quelle-a'),
        'email-templates.test.source_a'
    );
    registerFakeSource(
        fakeTemplateSource([['slug' => 'aus-b', 'title' => 'B', 'body' => '<p>B</p>']], 'quelle-b'),
        'email-templates.test.source_b'
    );

    Artisan::call('email-templates:import', ['--source' => 'quelle-a']);

    $manager = app(EmailTemplateCollectionManager::class);

    expect($manager->findBySlug('aus-a'))->not->toBeNull()
        ->and($manager->findBySlug('aus-b'))->toBeNull();
});

it('reports nothing to import when --source matches no source', function () {
    registerFakeSource(fakeTemplateSource([
        ['slug' => 'irgendwas', 'title' => 'X', 'body' => '<p>X</p>'],
    ]));

    expect(Artisan::call('email-templates:import', ['--source' => 'gibt-es-nicht']))->toBe(0);
    expect(Artisan::output())->toContain('No file-based templates found');
});

it('summarises the run', function () {
    app(EmailTemplateCollectionManager::class)->upsert(
        new EmailTemplateData(slug: 'vorhanden', title: 'Alt', body: '<p>ALT</p>')
    );

    registerFakeSource(fakeTemplateSource([
        ['slug' => 'vorhanden', 'title' => 'Neu', 'body' => '<p>NEU</p>'],
        ['slug' => 'frisch', 'title' => 'Frisch', 'body' => '<p>F</p>'],
    ]));

    Artisan::call('email-templates:import');

    expect(Artisan::output())->toContain('1 created, 0 updated, 1 skipped (of 2)');
});
