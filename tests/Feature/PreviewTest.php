<?php

use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Statamic\Facades\Collection;

// The collections/entries Stache is redirected to a per-process temp dir that
// persists across tests in a run, so clear any leftover entries up front.
beforeEach(function () {
    Collection::findByHandle('email_templates')?->queryEntries()->get()->each->delete();
});

// -- Slice 5: merge-variable substitution --------------------------------

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

// -- Slice 5: preview endpoint -------------------------------------------

it('renders a preview for a template slug with merge data', function () {
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'welcome',
        title: 'Welcome',
        subject: 'Hallo {{ contact.first_name }}',
        body: '<p>Schön dass du da bist, {{ contact.first_name }} {{ contact.last_name }}.</p>',
    ));

    $this->actingAsSuperUser();

    $response = $this->postJson(cp_route('email-templates.preview.render'), [
        'template' => 'welcome',
        'merge_data' => ['contact' => ['first_name' => 'Tomke']],
    ]);

    $response->assertOk();

    expect($response->json('subject'))->toBe('Hallo Tomke')
        ->and($response->json('source'))->toBe('entry')
        // Body is the Bard -> HTML render path with merge values substituted.
        ->and($response->json('body'))
            ->toContain('Schön dass du da bist, Tomke Beispiel')
            ->toContain('<p>');
});

it('renders a preview by entry id using the default sample set', function () {
    [$entry] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'promo',
        title: 'Promo',
        subject: 'Angebot für {{ contact.full_name }}',
        body: '<p>Hi {{ contact.first_name }}</p>',
    ));

    $this->actingAsSuperUser();

    $response = $this->postJson(cp_route('email-templates.preview.render'), [
        'id' => $entry->id(),
    ]);

    $response->assertOk();

    expect($response->json('subject'))->toBe('Angebot für Maria Beispiel')
        ->and($response->json('body'))->toContain('Hi Maria');
});

it('returns 404 for an unknown template slug', function () {
    $this->actingAsSuperUser();

    $this->postJson(cp_route('email-templates.preview.render'), ['template' => 'ghost'])
        ->assertNotFound();
});

it('returns 404 for an id outside the email_templates collection', function () {
    $this->actingAsSuperUser();

    $this->postJson(cp_route('email-templates.preview.render'), ['id' => 'nope-123'])
        ->assertNotFound();
});

// -- Slice 5: preview page -----------------------------------------------

it('shows the preview page listing the templates', function () {
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'welcome',
        title: 'Welcome Mail',
        body: '<p>Hi</p>',
    ));

    $this->actingAsSuperUser();

    $this->get(cp_route('email-templates.preview'))
        ->assertOk()
        ->assertSee('Welcome Mail')
        ->assertSee('welcome');
});
