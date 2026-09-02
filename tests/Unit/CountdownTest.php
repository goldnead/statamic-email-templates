<?php

use Carbon\Carbon;
use Goldnead\EmailTemplates\Support\Countdown;
use Goldnead\EmailTemplates\Support\CountdownImage;
use Goldnead\EmailTemplates\Support\MergeVariables;

/**
 * `{{ countdown }}` and `{{ countdown_image }}` are resolved by the same
 * MergeVariables::apply() that runs over every send, so these tests go through
 * apply() rather than through Countdown directly wherever the template author
 * would: what the author types is what is tested.
 */
beforeEach(function () {
    config()->set('app.timezone', 'Europe/Berlin');
    app()->setLocale('de');
    Carbon::setTestNow(Carbon::parse('2026-09-28 14:00:00', 'Europe/Berlin'));
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- Relative text ---------------------------------------------------------

it('prints days and hours with the absolute date by default', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-10-01 18:00" }}', []))
        ->toBe('noch 3 Tage, 4 Stunden (01.10.2026, 18:00 Uhr)');
});

it('uses the singular for one day and one hour', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-09-29 15:00" format="relative" }}', []))
        ->toBe('noch 1 Tag, 1 Stunde');
});

it('drops a zero hour after whole days', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-10-01 14:00" format="relative" }}', []))
        ->toBe('noch 3 Tage');
});

it('prints hours and minutes under a day', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-09-28 16:05" format="relative" }}', []))
        ->toBe('noch 2 Stunden, 5 Minuten');
});

it('prints minutes alone under an hour', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-09-28 14:12" format="relative" }}', []))
        ->toBe('noch 12 Minuten')
        ->and(MergeVariables::apply('{{ countdown until="2026-09-28 14:01" format="relative" }}', []))
        ->toBe('noch 1 Minute');
});

it('says so in words under a minute', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-09-28 14:00:30" format="relative" }}', []))
        ->toBe('noch weniger als eine Minute');
});

// -- Expired ---------------------------------------------------------------

it('says vorbei once the moment has passed', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-09-28 13:00" }}', []))
        ->toBe('vorbei (28.09.2026, 13:00 Uhr)');
});

it('treats the exact moment as passed', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-09-28 14:00" format="relative" }}', []))
        ->toBe('vorbei');
});

it('lets the template choose the expired wording', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-09-01" format="relative" expired="Anmeldung geschlossen" }}', []))
        ->toBe('Anmeldung geschlossen');
});

// -- Formats ---------------------------------------------------------------

it('prints only the absolute date on request', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-10-01 18:00" format="absolute" }}', []))
        ->toBe('01.10.2026, 18:00 Uhr');
});

it('falls back to both for an unknown format', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-10-01 18:00" format="fancy" }}', []))
        ->toBe('noch 3 Tage, 4 Stunden (01.10.2026, 18:00 Uhr)');
});

it('speaks English when the app does', function () {
    app()->setLocale('en');

    expect(MergeVariables::apply('{{ countdown until="2026-10-01 18:00" }}', []))
        ->toBe('3 days, 4 hours left (Oct 1, 2026, 18:00)')
        ->and(MergeVariables::apply('{{ countdown until="2026-09-01" format="relative" }}', []))
        ->toBe('over');
});

// -- Timezone --------------------------------------------------------------

it('reads a naive date in app.timezone and shows the absolute date there', function () {
    config()->set('app.timezone', 'America/New_York');

    // 14:00 Berlin is 08:00 New York; a naive "12:00" is New York noon, four hours away.
    expect(MergeVariables::apply('{{ countdown until="2026-09-28 12:00" }}', []))
        ->toBe('noch 4 Stunden (28.09.2026, 12:00 Uhr)');
});

it('converts a date with its own offset into app.timezone', function () {
    expect(MergeVariables::apply('{{ countdown until="2026-10-01T16:00:00Z" }}', []))
        ->toBe('noch 3 Tage, 4 Stunden (01.10.2026, 18:00 Uhr)');
});

// -- until from a variable --------------------------------------------------

it('takes until from a nested plain tag', function () {
    $data = ['event' => ['starts_at' => '2026-10-01 18:00']];

    expect(MergeVariables::apply('{{ countdown until="{{ event.starts_at }}" format="relative" }}', $data))
        ->toBe('noch 3 Tage, 4 Stunden');
});

it('takes until from a bare variable path', function () {
    $data = ['event' => ['starts_at' => '2026-10-01 18:00']];

    expect(MergeVariables::apply('{{ countdown until="event.starts_at" format="relative" }}', $data))
        ->toBe('noch 3 Tage, 4 Stunden');
});

it('accepts a DateTime object as the variable value', function () {
    $data = ['event' => ['starts_at' => Carbon::parse('2026-10-01 18:00', 'Europe/Berlin')]];

    expect(MergeVariables::apply('{{ countdown until="event.starts_at" format="relative" }}', $data))
        ->toBe('noch 3 Tage, 4 Stunden');
});

// -- Left in place ---------------------------------------------------------

it('leaves the tag standing when the variable is unknown', function () {
    $nested = '{{ countdown until="{{ event.starts_at }}" }}';
    $bare = '{{ countdown until="event.starts_at" }}';

    expect(MergeVariables::apply($nested, []))->toBe($nested)
        ->and(MergeVariables::apply($bare, []))->toBe($bare);
});

it('leaves the tag standing without an until or with an unparseable one', function () {
    expect(MergeVariables::apply('{{ countdown }}', []))->toBe('{{ countdown }}')
        ->and(MergeVariables::apply('{{ countdown until="irgendwann" }}', []))->toBe('{{ countdown until="irgendwann" }}');
});

it('does not touch plain tags that merely start with the word', function () {
    expect(MergeVariables::apply('{{ countdown_label }}', ['countdown_label' => 'Noch']))->toBe('Noch');
});

// -- Bard-escaped quotes ---------------------------------------------------

it('reads parameters whose quotes Bard escaped to entities', function () {
    expect(MergeVariables::apply('<p>{{ countdown until=&quot;2026-10-01 18:00&quot; format=&quot;relative&quot; }}</p>', []))
        ->toBe('<p>noch 3 Tage, 4 Stunden</p>');
});

// -- Image tag -------------------------------------------------------------

it('renders countdown_image as an img on the signed route', function () {
    $html = MergeVariables::apply('{{ countdown_image until="2026-10-01 18:00" width="480" }}', []);

    expect($html)->toStartWith('<img src="')
        ->toContain('/!/statamic-email-templates/countdown.png?')
        ->toContain('until=2026-10-01T18%3A00%3A00%2B02%3A00')
        ->toContain('w=480')
        ->toContain('signature=')
        ->toContain('width="480"')
        ->toContain('alt="Countdown bis 01.10.2026, 18:00 Uhr"');
});

it('passes colours and label through and clamps the width', function () {
    $html = MergeVariables::apply('{{ countdown_image until="2026-10-01 18:00" width="5000" bg="#000" fg="ffcc00" label="Bis zum Kursstart" }}', []);

    expect($html)->toContain('width="'.CountdownImage::MAX_WIDTH.'"')
        ->toContain('bg=000000')
        ->toContain('fg=ffcc00')
        ->toContain('label=Bis%20zum%20Kursstart');
});

it('emits the image tag as markup while escaping the values around it', function () {
    // The escaping pass runs *before* the function tags, so an `<img>` this
    // class builds itself is never escaped, while a name in the same paragraph
    // is. Both properties in one assertion on purpose: they are the two halves
    // of the same ordering decision.
    $html = MergeVariables::apply(
        '<p>Hallo {{ name }}</p>{{ countdown_image until="2026-10-01 18:00" }}',
        ['name' => '<b>Maria</b>']
    );

    expect($html)->toContain('<p>Hallo &lt;b&gt;Maria&lt;/b&gt;</p>')
        ->toContain('<img src="')
        ->not->toContain('&lt;img')
        ->not->toContain('&amp;amp;');
});

it('takes until from a variable that went through the escaping pass', function () {
    $data = ['event' => ['starts_at' => '2026-10-01 18:00']];

    expect(MergeVariables::apply('<p>{{ countdown until="{{ event.starts_at }}" format="relative" }}</p>', $data))
        ->toBe('<p>noch 3 Tage, 4 Stunden</p>');
});

it('leaves an invalid colour at its default', function () {
    expect(CountdownImage::colour('nope', 'ffffff'))->toBe('ffffff')
        ->and(CountdownImage::colour('#ABC', 'ffffff'))->toBe('aabbcc');
});

it('exposes remaining units for the image', function () {
    $countdown = Countdown::until('2026-10-01 18:05');

    expect($countdown?->remaining())->toMatchArray(['days' => 3, 'hours' => 4, 'minutes' => 5]);
});
