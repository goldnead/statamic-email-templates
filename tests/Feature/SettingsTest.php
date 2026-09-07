<?php

use Goldnead\EmailTemplates\Actions\SendTestEmail;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Snapshots\Snapshots;
use Goldnead\EmailTemplates\Support\CountdownImage;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Support\LayoutResolver;
use Goldnead\EmailTemplates\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Collection;
use Statamic\Facades\Permission;
use Symfony\Component\Mailer\SentMessage;

/**
 * The settings declaration, and the half of the chain this repository owns.
 *
 * The other half — the screen, the store and the `config()` override — belongs
 * to `goldnead/statamic-brand-context`, which is a suggest here and not in this
 * package's vendor directory. So these tests do what a test in this repository
 * can actually prove: that every offered key is read at *runtime*, by a reader
 * that sees a changed value, and that no offered key is one of the boot-time
 * readers where an override would arrive too late to matter. The chain from the
 * form to `config()` is verified against the real package in the playground.
 */

/** All keys the screen offers, flattened out of the group structure. */
function settingsKeys(): array
{
    return collect(Settings::settingsGroups())
        ->flatMap(fn (array $group) => $group['fields'])
        ->pluck('key')
        ->all();
}

it('offers only keys that exist in the packaged config', function () {
    $packaged = require __DIR__.'/../../config/email-templates.php';

    foreach (settingsKeys() as $key) {
        expect(data_get($packaged, $key, '__missing__'))->not->toBe('__missing__', $key);
    }
});

/*
 * The trap the shared layer sets: overrides are applied from `app->booted()`,
 * so anything read while booting has already seen the packaged value. A switch
 * on the screen that only takes effect on the next deploy is a lie in the
 * interface, so those keys must not be offered at all.
 */
it('offers no key that is read while the addon boots', function () {
    $bootTime = ['enabled'];

    expect(array_intersect($bootTime, settingsKeys()))->toBe([]);
});

it('offers no nested map', function () {
    expect(settingsKeys())->not->toContain('layouts')
        ->and(settingsKeys())->not->toContain('preview.sample_data');
});

it('names its section, its config root and its permission by the suite’s rule', function () {
    expect(Settings::settingsNamespace())->toBe('email-templates')
        ->and(Settings::settingsConfigPath())->toBe('email-templates')
        ->and(Settings::settingsPermission())->toBe('manage email-templates settings');
});

it('registers the permission it says it checks', function () {
    $handles = collect(Permission::all())->map->value()->all();

    expect($handles)->toContain('manage email-templates settings');
});

it('carries a label and a description for every offered field', function () {
    foreach (Settings::settingsGroups() as $group) {
        expect($group['title'])->not->toContain('email-templates::');
        expect($group['description'])->not->toContain('email-templates::');

        foreach ($group['fields'] as $field) {
            expect($field['label'])->not->toContain('email-templates::', $field['key']);
            expect($field['description'])->not->toContain('email-templates::', $field['key']);
        }
    }
});

it('keeps the German and English settings dictionaries key-identical', function () {
    $flatten = function (array $array, string $prefix = '') use (&$flatten) {
        $keys = [];
        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = array_merge($keys, is_array($value) ? $flatten($value, $path) : [$path]);
        }

        return $keys;
    };

    $de = $flatten(require __DIR__.'/../../resources/lang/de/settings.php');
    $en = $flatten(require __DIR__.'/../../resources/lang/en/settings.php');

    sort($de);
    sort($en);

    expect($de)->toBe($en);
});

/*
 * ── Each offered key, changed, and read back through its real reader.
 */

it('sends the changed test-mail prefix on an actual message', function () {
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)?->queryEntries()->get()->each->delete();

    config()->set('email-templates.branded_layout', null);
    config()->set('mail.from', ['address' => 'absender@example.com', 'name' => 'Absender']);
    config()->set('mail.default', 'array');

    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');
    Mail::clearResolvedInstances();

    [$entry] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: 'praefix-probe',
        title: 'Präfix-Probe',
        subject: 'Wochenbrief',
        body: '<p>Inhalt</p>',
    ));

    // The operator's value, not the packaged one.
    config()->set('email-templates.test_send.subject_prefix', '[Probe] ');

    (new SendTestEmail)->run(collect([$entry]), ['recipient' => 'adrian@example.test']);

    $subjects = array_map(
        fn (SentMessage $sent) => $sent->getOriginalMessage()->getSubject(),
        Mail::getSymfonyTransport()->messages()->all()
    );

    expect($subjects)->toBe(['[Probe] Wochenbrief']);
});

it('turns the countdown image off through its reader', function () {
    config()->set('email-templates.countdown.image', false);

    expect(CountdownImage::available())->toBeFalse();
});

it('changes the shell a template gets through the layout reader', function () {
    config()->set('email-templates.layouts', ['transaktional' => 'emails.lean']);
    config()->set('email-templates.default_layout', null);
    config()->set('email-templates.branded_layout', 'emails.shell');

    expect(LayoutResolver::resolve(null))->toBe('emails.shell');

    config()->set('email-templates.default_layout', 'transaktional');

    expect(LayoutResolver::resolve(null))->toBe('emails.lean');
});

it('stops recording sends through the snapshot reader', function () {
    Snapshots::flush();
    config()->set('email-templates.snapshots.enabled', false);

    expect(Snapshots::available())->toBeFalse();

    Snapshots::flush();
});
