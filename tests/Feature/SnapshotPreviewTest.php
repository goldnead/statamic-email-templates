<?php

use Goldnead\EmailTemplates\Snapshots\SnapshotPreview;
use Goldnead\EmailTemplates\Snapshots\Snapshots;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Snapshots::flush();

    $this->snapshot = Snapshots::record('marketing:campaign', 5, [
        'subject' => 'Hallo {{ contact.first_name }}',
        'body' => '<p>Guten Morgen, {{ contact.first_name }}. Antwort an {{ sender.email }}.</p>',
        'slug' => 'wochenbrief',
    ], [
        'sender_name' => 'Chorwerkstatt',
        'sender_email' => 'post@chorwerkstatt.test',
    ]);
});

it('shows the snapshot in the Control Panel with placeholder data', function () {
    $this->actingAsSuperUser();

    $response = $this->get(cp_route('email-templates.snapshots.preview', ['snapshot' => $this->snapshot->id]));

    $response->assertOk();

    $html = $response->getContent();

    // The sample recipient, not a real one — and the page says as much.
    expect($html)->toContain('Maria')
        ->and($html)->toContain(e(__('email-templates::email_templates.snapshot_notice_sample')))
        ->and($html)->not->toContain('{{ contact.first_name }}');
});

it('renders with the sender the snapshot recorded, not with today’s', function () {
    config()->set('mail.from.address', 'etwas.anderes@example.test');

    expect(SnapshotPreview::renderMail($this->snapshot))->toContain('post@chorwerkstatt.test');
});

it('says out loud that substituted contact values are today’s', function () {
    $document = SnapshotPreview::document($this->snapshot, [
        'contact' => ['first_name' => 'Jens'],
    ]);

    expect($document)->toContain('Jens')
        ->and($document)->toContain(e(__('email-templates::email_templates.snapshot_notice_today')))
        ->and($document)->not->toContain(e(__('email-templates::email_templates.snapshot_notice_sample')));
});

it('stores nothing when a snapshot is previewed with real values', function () {
    SnapshotPreview::document($this->snapshot, [
        'contact' => ['first_name' => 'Jens', 'email' => 'jens.taube@example.test'],
    ]);

    $stored = $this->snapshot->fresh();

    expect($stored->body)->toContain('{{ contact.first_name }}')
        ->and($stored->body)->not->toContain('Jens')
        ->and($stored->body)->not->toContain('jens.taube@example.test');
});

it('answers 404 for a snapshot that is not there', function () {
    $this->actingAsSuperUser();

    $this->get(cp_route('email-templates.snapshots.preview', ['snapshot' => 999999]))
        ->assertNotFound();
});

/*
 * Die Sendezeit im Kasten stand in der Zeitzone der ANWENDUNG, die Zeit im Kopf
 * derselben Seite in der des Browsers. Auf adriangoldner.com ist `app.timezone`
 * UTC, also standen dort zwei Zeiten fuer dieselbe Sendung, zwei Stunden
 * auseinander: „Sent 18.9.2026, 20:03:50" oben, „Sent on 18.09.2026, 18:03"
 * unten (gemessen am 18.09.2026).
 *
 * Die Zeitzone der Anwendung zu drehen waere der falsche Hebel: die Spalten
 * halten UTC-Wanduhr ohne Kennung, und jeder bestehende Zeitstempel wuerde ab
 * dann zwei Stunden zu frueh gelesen — lautlos. Gespeichert bleibt also UTC,
 * und angezeigt wird in `Statamic::displayTimezone()`, der Schraube, die
 * Statamic fuer genau diese Frage schon mitbringt.
 */
it('shows the send time in the display timezone, not the application one', function () {
    config()->set('app.timezone', 'UTC');
    config()->set('statamic.system.display_timezone', 'Europe/Berlin');

    Snapshots::flush();

    $snapshot = Snapshots::record('marketing:campaign', 7, [
        'subject' => 'Zeitprobe',
        'body' => '<p>Zeitprobe</p>',
    ]);

    // Genau der Wert, der auf staging in der Spalte steht: UTC-Wanduhr.
    $snapshot->forceFill(['last_sent_at' => '2026-09-18 18:03:50'])->save();

    $dokument = SnapshotPreview::document($snapshot->fresh());

    expect($dokument)->toContain('18.09.2026, 20:03')
        ->and($dokument)->not->toContain('18.09.2026, 18:03');
});

it('falls back to the application timezone where no display timezone is set', function () {
    config()->set('app.timezone', 'UTC');
    config()->set('statamic.system.display_timezone', null);

    Snapshots::flush();

    $snapshot = Snapshots::record('marketing:campaign', 8, [
        'subject' => 'Zeitprobe',
        'body' => '<p>Zeitprobe</p>',
    ]);
    $snapshot->forceFill(['last_sent_at' => '2026-09-18 18:03:50'])->save();

    // Kein Verhalten erfunden: ohne eigene Einstellung bleibt alles wie bisher.
    expect(SnapshotPreview::document($snapshot->fresh()))->toContain('18.09.2026, 18:03');
});

it('does not wrap a snapshot that is already a complete document', function () {
    Snapshots::flush();

    $snapshot = Snapshots::record('marketing:campaign', 6, [
        'subject' => 'Fertig',
        'body' => '<!doctype html><html><body><p>Schon gerahmt</p></body></html>',
    ]);

    config()->set('email-templates.branded_layout', 'layout');

    expect(substr_count(SnapshotPreview::renderMail($snapshot), '<html'))->toBe(1);
});

/*
 * Die Vorschau zeigt nur Snapshots der aktuellen Marke. Auf einem
 * Mehrmarken-Host sah sonst jeder angemeldete Nutzer die Versandhistorie aller
 * Marken (Ticket backlog-snapshot-vorschau-ohne-eigenes-recht).
 */
function snapshotBrandContext(?string $current, bool $multi = true): void
{
    app()->instance('brand-context', new class($current, $multi)
    {
        public function __construct(private ?string $current, private bool $multi) {}

        public function multiBrandEnabled(): bool
        {
            return $this->multi;
        }

        public function hasCurrent(): bool
        {
            return $this->current !== null;
        }

        public function current(): object
        {
            return (object) ['handle' => $this->current, 'name' => $this->current];
        }

        public function default(): object
        {
            return (object) ['handle' => 'chorwerkstatt', 'name' => 'chorwerkstatt'];
        }
    });
}

it('shows a snapshot to its own brand and hides it from another brand', function () {
    Snapshots::flush();

    $snapshot = Snapshots::record('marketing:campaign', 11, [
        'subject' => 'Nur fuer Chorwerkstatt',
        'body' => '<p>Nur fuer Chorwerkstatt</p>',
    ], ['brand' => 'chorwerkstatt']);

    $this->actingAsSuperUser();
    $url = cp_route('email-templates.snapshots.preview', ['snapshot' => $snapshot->id]);

    snapshotBrandContext('chorwerkstatt');
    $this->get($url)->assertOk();

    snapshotBrandContext('nordlicht');
    $this->get($url)->assertNotFound();
});

it('keeps serving every snapshot on a single-brand install', function () {
    Snapshots::flush();

    $snapshot = Snapshots::record('marketing:campaign', 12, [
        'subject' => 'Einmarke',
        'body' => '<p>Einmarke</p>',
    ], ['brand' => 'chorwerkstatt']);

    snapshotBrandContext('nordlicht', multi: false);
    $this->actingAsSuperUser();

    $this->get(cp_route('email-templates.snapshots.preview', ['snapshot' => $snapshot->id]))->assertOk();
});
