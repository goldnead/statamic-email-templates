<?php

use Facades\Statamic\CP\LivePreview;
use Goldnead\EmailTemplates\Entries\EmailTemplateEntry;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Statamic\Facades\Collection;

// The collections/entries Stache is redirected to a per-process temp dir that
// persists across tests in a run, so clear any leftover entries up front.
beforeEach(function () {
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)?->queryEntries()->get()->each->delete();
});

// -- Merge-variable substitution -----------------------------------------

it('substitutes known merge variables and leaves unknown tags in place', function () {
    $out = MergeVariables::apply(
        'Hallo {{ contact.first_name }} {{ contact.last_name }}, Code {{ contact.unknown }}.',
        MergeVariables::sampleData(['contact' => ['first_name' => 'Tomke']])
    );

    expect($out)->toBe('Hallo Tomke Beispiel, Code {{ contact.unknown }}.');
});

it('exposes a documented default sample set', function () {
    $defaults = MergeVariables::defaults();

    expect($defaults['contact']['first_name'])->toBe('Maria')
        ->and($defaults['contact']['email'])->toBe('maria.beispiel@example.com')
        ->and($defaults)->toHaveKey('unsubscribe_url');
});

// -- Live Preview wiring --------------------------------------------------

it('registers the et_templates collection with a live-preview target and custom entry class', function () {
    $collection = Collection::findByHandle(EmailTemplateCollectionManager::HANDLE);

    expect($collection)->not->toBeNull()
        ->and($collection->entryClass())->toBe(EmailTemplateEntry::class)
        ->and($collection->previewTargets()->pluck('format'))
        ->toContain('/'.EmailTemplateCollectionManager::LIVE_PREVIEW_ROUTE);
});

// -- Live Preview render route -------------------------------------------

it('renders the live-preview email html from a token', function () {
    [$entry] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'welcome',
        title: 'Welcome',
        subject: 'Hallo {{ contact.first_name }}',
        body: '<p>Schön dass du da bist, {{ contact.first_name }} {{ contact.last_name }}.</p>',
    ));

    // Simulate what the CP does before opening Live Preview: tokenize the
    // (here already-saved) entry and load the render route with the token.
    LivePreview::tokenize('lp-token', $entry);

    $response = $this->get('/'.EmailTemplateCollectionManager::LIVE_PREVIEW_ROUTE.'?token=lp-token');

    $response->assertOk();

    // Body is the Bard -> HTML render path with merge values substituted, and
    // the subject is rendered into the header bar.
    expect($response->getContent())
        ->toContain('Schön dass du da bist, Maria Beispiel')
        ->toContain('Hallo Maria');
});

it('shows a neutral placeholder without a valid token', function () {
    $this->get('/'.EmailTemplateCollectionManager::LIVE_PREVIEW_ROUTE)
        ->assertOk();
});
