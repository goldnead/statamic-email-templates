<?php

use Goldnead\EmailTemplates\Snapshots\EmailTemplateSnapshot;
use Goldnead\EmailTemplates\Snapshots\Snapshots;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    Snapshots::flush();
});

it('writes one row for a send, whatever the number of recipients', function () {
    $snapshot = Snapshots::record('marketing:campaign', 17, [
        'subject' => 'Hallo {{ contact.first_name }}',
        'body' => '<p>Guten Morgen, {{ contact.first_name }}.</p>',
        'slug' => 'wochenbrief',
    ]);

    expect($snapshot)->not->toBeNull()
        ->and(EmailTemplateSnapshot::count())->toBe(1)
        ->and($snapshot->owner_type)->toBe('marketing:campaign')
        ->and($snapshot->owner_id)->toBe('17')
        ->and($snapshot->send_count)->toBe(1);
});

it('reuses the row when the same owner sends the same template again', function () {
    $payload = [
        'subject' => 'Hallo {{ contact.first_name }}',
        'body' => '<p>Unverändert.</p>',
    ];

    $first = Snapshots::record('automations:node', 'flow-1:node-9', $payload);
    $second = Snapshots::record('automations:node', 'flow-1:node-9', $payload);
    $third = Snapshots::record('automations:node', 'flow-1:node-9', $payload);

    expect(EmailTemplateSnapshot::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($third->fresh()->send_count)->toBe(3);
});

it('writes a second row once the template has changed', function () {
    Snapshots::record('marketing:campaign', 17, ['subject' => 'Vorher', 'body' => '<p>a</p>']);
    Snapshots::record('marketing:campaign', 17, ['subject' => 'Nachher', 'body' => '<p>a</p>']);

    expect(EmailTemplateSnapshot::count())->toBe(2)
        ->and(Snapshots::forOwner('marketing:campaign', 17))->toHaveCount(2)
        ->and(Snapshots::latestForOwner('marketing:campaign', 17)->subject)->toBe('Nachher');
});

it('keeps two owners apart even when they send identical content', function () {
    Snapshots::record('marketing:campaign', 1, ['subject' => 'Gleich', 'body' => '<p>a</p>']);
    Snapshots::record('notifications:type', 'digest', ['subject' => 'Gleich', 'body' => '<p>a</p>']);

    expect(EmailTemplateSnapshot::count())->toBe(2);
});

it('records the layout, the sender and the template source', function () {
    config()->set('email-templates.branded_layout', 'emails.layout');

    $snapshot = Snapshots::record('marketing:campaign', 3, new EmailTemplateData(
        slug: 'willkommen',
        title: 'Willkommen',
        subject: 'Willkommen',
        body: '<p>Hallo</p>',
        layout: null,
        source: 'entry',
    ), [
        'brand' => 'chorwerkstatt',
        'sender_name' => 'Chorwerkstatt',
        'sender_email' => 'post@chorwerkstatt.test',
    ]);

    expect($snapshot->template_slug)->toBe('willkommen')
        ->and($snapshot->template_source)->toBe('entry')
        ->and($snapshot->layout_view)->toBe('emails.layout')
        ->and($snapshot->brand)->toBe('chorwerkstatt')
        ->and($snapshot->sender_email)->toBe('post@chorwerkstatt.test');
});

/*
 * The rule this layer exists for: the template goes in, never the mail of a
 * named person. A consumer reaching for the rendered HTML it just built is the
 * shortcut that would quietly turn this table into a store of personal data —
 * and with it into something that needs a deletion concept.
 */
it('stores the template with its placeholders, not the mail a contact received', function () {
    $template = '<p>Hallo {{ contact.first_name }}, deine Adresse ist {{ contact.email }}.</p>';

    // What a real send does to the template for one recipient.
    $rendered = MergeVariables::apply($template, [
        'contact' => ['first_name' => 'Jens', 'email' => 'jens.taube@example.test'],
    ]);

    expect($rendered)->toContain('Jens');

    $snapshot = Snapshots::record('marketing:campaign', 42, [
        'subject' => 'Hallo {{ contact.first_name }}',
        'body' => $template,
    ]);

    $stored = $snapshot->fresh();

    expect($stored->body)->toContain('{{ contact.first_name }}')
        ->and($stored->body)->not->toContain('Jens')
        ->and($stored->body)->not->toContain('jens.taube@example.test')
        ->and($stored->subject)->toContain('{{ contact.first_name }}');
});

it('refuses a body that carries a per-recipient tracking pixel', function () {
    Log::spy();

    $result = Snapshots::record('marketing:campaign', 42, [
        'subject' => 'Wochenbrief',
        'body' => '<p>Text</p><img src="https://example.test/!/marketing/o/9f8b7c6d-1a2b-3c4d-5e6f-708192a3b4c5.gif" width="1">',
    ]);

    expect($result)->toBeNull()
        ->and(EmailTemplateSnapshot::count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'rendered mail'));
});

it('refuses a body that carries a signed per-recipient link', function () {
    $result = Snapshots::record('marketing:campaign', 42, [
        'subject' => 'Wochenbrief',
        'body' => '<p><a href="https://example.test/abmelden?expires=1&signature=8f14e45fceea167a5a36dedd4bea2543">Abmelden</a></p>',
    ]);

    expect($result)->toBeNull()
        ->and(EmailTemplateSnapshot::count())->toBe(0);
});

it('lets an unsigned link through, because a template may well carry one', function () {
    $snapshot = Snapshots::record('marketing:campaign', 42, [
        'subject' => 'Wochenbrief',
        'body' => '<p><a href="{{ unsubscribe_url }}">Abmelden</a> · <a href="https://chorwerkstatt.test/kurse">Kurse</a></p>',
    ]);

    expect($snapshot)->not->toBeNull();
});

it('records nothing and stays quiet when snapshots are switched off', function () {
    config()->set('email-templates.snapshots.enabled', false);
    Snapshots::flush();

    expect(Snapshots::record('marketing:campaign', 1, ['subject' => 'x', 'body' => '<p>x</p>']))->toBeNull()
        ->and(Snapshots::available())->toBeFalse()
        ->and(Snapshots::previewUrl(1))->toBeNull()
        ->and(EmailTemplateSnapshot::count())->toBe(0);
});
