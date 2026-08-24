<?php

use Goldnead\EmailTemplates\Support\MergeVariables;

/*
|--------------------------------------------------------------------------
| Absender in der Vorschau (2026-08-24)
|--------------------------------------------------------------------------
|
| Bis dahin las die Vorschau `config('mail.from.*')` bedingungslos. Auf einem
| Host mit mehreren Marken zeigte damit jede Vorlage denselben — und für alle
| Marken bis auf eine falschen — Absender. Gesendet wurde so nie etwas, aber
| eine Vorschau, deren Absender gelogen ist, taugt nicht für das eine, wofür
| man sie aufmacht.
|
*/

it('falls back to the host configuration when brand-context is absent', function () {
    config()->set('mail.from.name', 'Host');
    config()->set('mail.from.address', 'host@example.com');

    $sender = MergeVariables::sampleData()['sender'] ?? null;

    // brand-context is not required by this package, and an installation
    // without it must keep working exactly as before.
    expect($sender['email'])->toBe('host@example.com');
    expect($sender['name'])->toBe('Host');
});

it('never leaves the sender empty', function () {
    config()->set('mail.from.name', null);
    config()->set('mail.from.address', null);
    config()->set('app.name', null);

    $sender = MergeVariables::sampleData()['sender'] ?? null;

    // A preview with an empty From reads as a broken template rather than as a
    // missing setting.
    expect($sender['email'])->not->toBeEmpty();
    expect($sender['name'])->not->toBeEmpty();
});
