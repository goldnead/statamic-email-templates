<?php

use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Services\EmailTemplateResolver;
use Goldnead\EmailTemplates\Support\EmailTemplateBlueprint;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * The `preview` field is the email preheader: a short line inbox clients show
 * next to the subject. It flows blueprint -> DTO -> resolver like the subject,
 * and is injected into the sent HTML as the standard hidden preheader div whose
 * merge-variable tokens the send-time substitution resolves.
 */
beforeEach(function () {
    // Start from a clean collection; no branded layout so we assert the raw
    // send body (branding is covered by BrandedRenderingTest).
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)?->queryEntries()->get()->each->delete();
    config()->set('email-templates.branded_layout', null);
});

it('exposes a preview (preheader) field on the et_templates blueprint', function () {
    $blueprint = Blueprint::find(
        EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE
    );

    expect($blueprint)->not->toBeNull()
        ->and($blueprint->hasField('preview'))->toBeTrue()
        ->and($blueprint->field('preview')->type())->toBe('text');
});

it('resolves the preview text from an entry, like the subject', function () {
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'welcome',
        title: 'Welcome',
        subject: 'Betreff',
        preview: 'Hallo {{ contact.first_name }}',
        body: '<p>Inhalt.</p>',
    ));

    $resolved = app(EmailTemplateResolver::class)->resolve('welcome');

    expect($resolved)->not->toBeNull()
        // Preview is carried on the DTO with its merge tokens intact, exactly
        // like the subject (the consuming addon substitutes at send time).
        ->and($resolved->preview)->toBe('Hallo {{ contact.first_name }}');
});

it('injects a hidden preheader div carrying the resolved preview into the sent body', function () {
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'welcome',
        title: 'Welcome',
        subject: 'Betreff',
        preview: 'Hallo {{ contact.first_name }}',
        body: '<p>Inhalt.</p>',
    ));

    $resolved = app(EmailTemplateResolver::class)->resolve('welcome');

    // The consuming send addon runs the body through the same merge-variable
    // pass as the subject; the injected preheader tokens resolve with it.
    $sent = MergeVariables::apply($resolved->body, MergeVariables::sampleData());

    expect($sent)
        ->toContain('display:none')
        ->toContain('Hallo Maria')
        ->toContain('<p>Inhalt.</p>');

    // The hidden preheader precedes the visible body.
    expect(strpos($sent, 'display:none'))->toBeLessThan(strpos($sent, '<p>Inhalt.</p>'));
});

it('injects no preheader div when the preview text is blank', function () {
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'plain',
        title: 'Plain',
        subject: 'Betreff',
        body: '<p>Inhalt.</p>',
    ));

    $resolved = app(EmailTemplateResolver::class)->resolve('plain');

    expect($resolved->body)
        ->not->toContain('display:none')
        ->toContain('<p>Inhalt.</p>');
});
