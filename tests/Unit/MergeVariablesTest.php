<?php

use Goldnead\EmailTemplates\Support\MergeVariables;

/**
 * MergeVariables decides what lands in a real recipient's inbox: the same
 * `apply()` runs over the CP preview and over every real send, so a change here
 * changes outgoing mail. These tests pin the contract, including the parts that
 * are deliberately permissive.
 */

// -- Substitution ---------------------------------------------------------

it('replaces a flat tag', function () {
    expect(MergeVariables::apply('Hallo {{ name }}.', ['name' => 'Maria']))
        ->toBe('Hallo Maria.');
});

it('replaces a nested tag by dotted path', function () {
    expect(MergeVariables::apply('{{ contact.first_name }}', ['contact' => ['first_name' => 'Maria']]))
        ->toBe('Maria');
});

it('replaces a deeply nested tag', function () {
    $data = ['a' => ['b' => ['c' => 'tief']]];

    expect(MergeVariables::apply('{{ a.b.c }}', $data))->toBe('tief');
});

it('replaces every occurrence of the same tag', function () {
    expect(MergeVariables::apply('{{ n }}-{{ n }}-{{ n }}', ['n' => 'x']))
        ->toBe('x-x-x');
});

it('tolerates any amount of whitespace inside the braces', function () {
    $data = ['contact' => ['first_name' => 'Maria']];

    expect(MergeVariables::apply('{{contact.first_name}}', $data))->toBe('Maria')
        ->and(MergeVariables::apply('{{   contact.first_name   }}', $data))->toBe('Maria')
        ->and(MergeVariables::apply("{{\tcontact.first_name\n}}", $data))->toBe('Maria');
});

// -- Missing and malformed ------------------------------------------------

it('leaves an unknown tag in place so the author can see the typo', function () {
    expect(MergeVariables::apply('Hallo {{ contact.nope }}.', ['contact' => ['first_name' => 'Maria']]))
        ->toBe('Hallo {{ contact.nope }}.');
});

it('leaves a tag in place when the data is empty', function () {
    expect(MergeVariables::apply('{{ name }}', []))->toBe('{{ name }}');
});

it('leaves an intermediate path in place rather than rendering an array', function () {
    // `contact` is a branch, not a leaf. Flattening drops branches, so the tag
    // stays visible instead of turning into "Array".
    expect(MergeVariables::apply('{{ contact }}', ['contact' => ['first_name' => 'Maria']]))
        ->toBe('{{ contact }}');
});

it('does not touch malformed or foreign brace syntax', function () {
    $data = ['name' => 'Maria'];

    expect(MergeVariables::apply('{{ name', $data))->toBe('{{ name')
        ->and(MergeVariables::apply('name }}', $data))->toBe('name }}')
        ->and(MergeVariables::apply('{ name }', $data))->toBe('{ name }')
        // Antlers modifiers and tag pairs are not merge variables.
        ->and(MergeVariables::apply('{{ name|upper }}', $data))->toBe('{{ name|upper }}')
        ->and(MergeVariables::apply('{{ if name }}', $data))->toBe('{{ if name }}');
});

it('returns an empty string untouched', function () {
    expect(MergeVariables::apply('', ['name' => 'Maria']))->toBe('');
});

it('leaves text without any tags untouched', function () {
    expect(MergeVariables::apply('Nur Text.', ['name' => 'Maria']))->toBe('Nur Text.');
});

// -- Value coercion --------------------------------------------------------

it('casts scalar values to string', function () {
    expect(MergeVariables::apply('{{ n }}|{{ f }}|{{ t }}|{{ z }}', [
        'n' => 42,
        'f' => 1.5,
        't' => true,
        'z' => false,
    ]))->toBe('42|1.5|1|');
});

it('renders a null value as an empty string, not as the tag', function () {
    expect(MergeVariables::apply('[{{ n }}]', ['n' => null]))->toBe('[]');
});

it('renders a stringable object through __toString', function () {
    $value = new class
    {
        public function __toString(): string
        {
            return 'aus dem Objekt';
        }
    };

    expect(MergeVariables::apply('{{ v }}', ['v' => $value]))->toBe('aus dem Objekt');
});

// -- Escaping: the documented trust boundary -------------------------------

it('escapes a substituted value on its way into HTML', function () {
    // A merge value is recipient data. A name carrying markup belongs in the
    // mail as text; until 02.09.2026 it landed as markup, in a mail sent to an
    // address nobody had verified.
    expect(MergeVariables::apply('<p>{{ name }}</p>', ['name' => '<b>Maria</b>']))
        ->toBe('<p>&lt;b&gt;Maria&lt;/b&gt;</p>');
});

it('escapes a script tag in a value', function () {
    expect(MergeVariables::apply('<p>Hallo {{ name }}</p>', ['name' => '<script>alert(1)</script>']))
        ->toBe('<p>Hallo &lt;script&gt;alert(1)&lt;/script&gt;</p>')
        ->not->toContain('<script>');
});

it('escapes an ampersand exactly once', function () {
    expect(MergeVariables::apply('{{ choir }}', ['choir' => 'Müller & Söhne']))
        ->toBe('Müller &amp; Söhne')
        ->not->toContain('&amp;amp;');
});

it('leaves the keys named in RAW_VARIABLES raw', function () {
    expect(MergeVariables::RAW_VARIABLES)->toContain('unsubscribe_url');

    expect(MergeVariables::apply(
        '<a href="{{ unsubscribe_url }}">Abmelden</a>',
        ['unsubscribe_url' => 'https://example.com/abmelden?id=7&sig=abc']
    ))->toBe('<a href="https://example.com/abmelden?id=7&sig=abc">Abmelden</a>');
});

it('substitutes verbatim when the caller asks for raw output', function () {
    // What a subject line and a plain-text part get: neither is HTML, and an
    // escaped `&` would be visible to the reader as `&amp;`.
    expect(MergeVariables::apply('Post von {{ choir }}', ['choir' => 'Müller & Söhne'], escape: false))
        ->toBe('Post von Müller & Söhne');
});

it('does not re-process a substituted value that itself looks like a tag', function () {
    // One pass only — a value containing `{{ … }}` is not expanded again, so a
    // hostile value cannot reach other merge data.
    expect(MergeVariables::apply('{{ a }}', ['a' => '{{ secret }}', 'secret' => 'geheim']))
        ->toBe('{{ secret }}');
});

// -- Sample data -----------------------------------------------------------

it('exposes the documented default sample set', function () {
    $defaults = MergeVariables::defaults();

    expect($defaults['contact']['first_name'])->toBe('Maria')
        ->and($defaults['contact']['last_name'])->toBe('Beispiel')
        ->and($defaults['contact']['full_name'])->toBe('Maria Beispiel')
        ->and($defaults['contact']['email'])->toBe('maria.beispiel@example.com')
        ->and($defaults['contact']['salutation'])->toBe('Hallo Maria')
        ->and($defaults)->toHaveKey('sender.name')
        ->and($defaults)->toHaveKey('sender.email')
        ->and($defaults['unsubscribe_url'])->toBe('https://example.com/newsletter/abmelden')
        ->and($defaults['date'])->toBe(date('d.m.Y'));
});

it('takes the sender from the mail config', function () {
    config()->set('mail.from.name', 'Adrian');
    config()->set('mail.from.address', 'info@example.test');

    $defaults = MergeVariables::defaults();

    expect($defaults['sender']['name'])->toBe('Adrian')
        ->and($defaults['sender']['email'])->toBe('info@example.test');
});

it('falls back to the app name when no mail sender is configured', function () {
    config()->set('mail.from.name', null);
    config()->set('app.name', 'Testseite');

    expect(MergeVariables::defaults()['sender']['name'])->toBe('Testseite');
});

it('deep-merges configured sample data over the built-in defaults', function () {
    config()->set('email-templates.preview.sample_data', [
        'contact' => ['first_name' => 'Tomke'],
        'eigenes' => 'feld',
    ]);

    $defaults = MergeVariables::defaults();

    expect($defaults['contact']['first_name'])->toBe('Tomke')
        // Siblings survive the merge.
        ->and($defaults['contact']['last_name'])->toBe('Beispiel')
        ->and($defaults['eigenes'])->toBe('feld');
});

it('ignores a non-array sample_data config', function () {
    config()->set('email-templates.preview.sample_data', 'kaputt');

    expect(MergeVariables::defaults()['contact']['first_name'])->toBe('Maria');
});

it('merges per-call overrides on top of the defaults', function () {
    $data = MergeVariables::sampleData(['contact' => ['first_name' => 'Tomke']]);

    expect($data['contact']['first_name'])->toBe('Tomke')
        ->and($data['contact']['last_name'])->toBe('Beispiel');
});

it('returns the plain defaults when no overrides are passed', function () {
    expect(MergeVariables::sampleData())->toBe(MergeVariables::defaults());
});
