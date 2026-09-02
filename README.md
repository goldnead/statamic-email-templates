<!-- statamic:hide -->

# Statamic Email Templates

> Write your transactional and marketing emails in the Control Panel you already use.

<!-- /statamic:hide -->

Email Templates gives Statamic a managed `et_templates` collection: your emails are
ordinary entries, authored in Bard, edited in the native publish form, and previewed
with Statamic's own Live Preview while you type. Other code — your app, a queued job,
a sibling addon — asks for a template by slug and gets ready-to-send HTML back.

The addon ships **no Control Panel screens of its own**. The listing, the editor,
search, filters, sorting, permissions, localisation and dark mode are core's.

## Requirements

| | |
|---|---|
| Statamic | 6.0+ |
| PHP | 8.2+ |
| Laravel | 12.40+ or 13 (whatever Statamic 6 pulls in) |
| Database | not required — templates are flat-file entries |

## Installation

```
composer require goldnead/statamic-email-templates
```

That is the whole setup. On the next request the addon creates the `et_templates`
collection and its blueprint, and adds **Email templates** to the *Content* section
of the CP nav. Open it and press *Create Entry*.

To publish the config file:

```
php artisan vendor:publish --tag=email-templates-config
```

## Usage

### Authoring

Each template entry has:

| Field | What it is |
|---|---|
| `title` | Internal name, shown in the listing |
| `slug` | **The stable reference.** Code looks templates up by this and it is never rewritten |
| `subject` | The email subject line. Merge variables allowed |
| `preview` | Preheader text — the line inbox clients show next to the subject |
| `layout` | Which configured shell wraps this template (see *Configuration*) |
| `body` | The email itself, as Bard |
| `plain_text` | Optional `text/plain` alternative |
| `description` | A note to your future self |

### Reading a template from code

```php
use Goldnead\EmailTemplates\Facades\EmailTemplates;

$template = EmailTemplates::resolve('welcome');

$template?->subject;    // string, merge tags not yet substituted
$template?->body;       // email-ready HTML
$template?->plain_text; // string|null
```

`resolve()` takes an optional fallback for slugs that have not been migrated into
the collection yet. A managed entry always wins:

```php
$template = EmailTemplates::resolve($slug, function (string $slug) {
    return ['title' => '…', 'body' => '<p>…</p>']; // or null
});
```

### Merge variables

Templates use `{{ dotted.key }}` placeholders in the subject, the preheader and the
body. At send time your code supplies real recipient data; the CP preview supplies a
documented sample set, so a template can be previewed without a real contact.

```php
use Goldnead\EmailTemplates\Support\MergeVariables;

$subject = MergeVariables::apply($template->subject, [
    'contact' => ['first_name' => $user->first_name],
]);
```

Unknown tags are **left visible** rather than silently removed, so a typo shows up
in the preview instead of in someone's inbox. Values are inserted verbatim, without
HTML escaping — sanitise recipient-controlled data before you pass it in.

The default sample set, overridable via `email-templates.preview.sample_data`:

| Variable | Sample value |
| --- | --- |
| `{{ contact.first_name }}` | `Maria` |
| `{{ contact.last_name }}` | `Beispiel` |
| `{{ contact.full_name }}` | `Maria Beispiel` |
| `{{ contact.email }}` | `maria.beispiel@example.com` |
| `{{ contact.salutation }}` | `Hallo Maria` |
| `{{ sender.name }}` | `config('mail.from.name')` |
| `{{ sender.email }}` | `config('mail.from.address')` |
| `{{ unsubscribe_url }}` | `https://example.com/newsletter/abmelden` |
| `{{ date }}` | today, `d.m.Y` |

### Countdown

For launch mails — a course opens, registration closes — a template can print how long
is left. Two tags, resolved by the same `MergeVariables::apply()` pass as everything else.

**Text (use this one):**

```
{{ countdown until="2026-10-01 18:00" }}
→ noch 3 Tage, 4 Stunden (01.10.2026, 18:00 Uhr)
```

The time is computed **when the mail is rendered** and then stands still, like everything
else in an email. That is honest: the recipient reads "sent when there were 3 days left"
and the absolute date next to it stays right forever. It works in every mail client, needs
no route and no image. For nine out of ten launch mails this is all you want.

| Parameter | Effect |
| --- | --- |
| `until` | Required. A date Carbon can parse, read in `app.timezone`; or a variable — `until="{{ event.starts_at }}"` and `until="event.starts_at"` both work |
| `format` | `both` (default), `relative` ("noch 3 Tage, 4 Stunden") or `absolute` ("01.10.2026, 18:00 Uhr") |
| `expired` | Text once the moment has passed; default "vorbei" / "over" |

The relative part names the two largest non-zero units and switches to "noch weniger als
eine Minute" under a minute. German and English follow the app locale. A tag whose `until`
cannot be resolved is left standing, like any unknown variable.

**Image (on request only):**

```
{{ countdown_image until="2026-10-01 18:00" width="480" label="Bis zum Kursstart" }}
```

renders an `<img>` pointing at `GET /!/statamic-email-templates/countdown.png?…`, a
signed, rate-limited (`throttle:60,1`) route that draws "dd : hh : mm" with GD at the
moment the client fetches it, cached for 60 seconds. After the moment has passed it shows
`00 : 00 : 00` and the expired text. Parameters: `width` (200–1200, default 600), `bg` and
`fg` as hex colours, `label`, `expired`, `alt`. Needs `ext-gd`; without it the route answers
404 and logs a warning. `email-templates.countdown.image => false` switches it off.

Know what you are buying before you use it:

- **Gmail** fetches images through its proxy on every open, so the picture is current each
  time — and each open is a request to your server.
- **Apple Mail Privacy Protection** fetches every image once, in advance, from Apple's
  servers, at a moment of Apple's choosing. From then on the recipient sees that cached
  frame: a countdown that is wrong by however long ago Apple looked.
- **Outlook desktop** blocks remote images until the reader allows them.

None of that touches the text tag, which is why it comes first.

### Live Preview

Open a template and press Statamic's Live Preview button: the split-screen renders
the actual email — Bard to HTML, merge variables substituted, wrapped in the layout
that would really wrap it — and updates as you type.

Email templates are not web pages, so the collection has **no front-end route**.
Entries are instantiated as `EmailTemplateEntry`, which enables the Live Preview
button without giving templates a public URL. The split-screen iframe is served by
`GET /email-templates/live-preview`, which only renders a body for a valid,
short-lived Live Preview token and shows a neutral placeholder otherwise.

## Configuration

`config/email-templates.php`:

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` | Master switch. `false` stops the addon creating the collection and adding the nav item. The resolver and the import command stay callable. |
| `branded_layout` | `null` | A Blade view that wraps every rendered body — your header, footer and styling. It must contain `@yield('content')`; the subject arrives as `$title`. `null` renders bodies unwrapped. |
| `layouts` | `[]` | A `handle => view` map. The keys populate the `layout` select on each entry, so a transactional mail can pick a lean shell and a campaign a marketing one. |
| `default_layout` | `null` | A handle from `layouts`, used for entries that pick none. |
| `preview.sample_data` | see above | Deep-merged over the built-in merge-variable sample set. |

Layout resolution for an entry: its own `layout`, else `default_layout`, else
`branded_layout`. An unknown handle or a missing view falls through the chain —
nothing throws mid-send.

## Permissions

The addon registers **no permissions of its own**. Access is governed entirely by
the collection's native permissions, which Statamic generates:

- `view et_templates entries` — controls the nav item and the listing
- `edit et_templates entries`, `create et_templates entries`, `delete et_templates entries`

Grant them under *Users → Roles* like any other collection. A role without
`view et_templates entries` does not see the nav item.

## Multi-site

Templates are shared across sites, and the wording is localisable per site:
`title`, `subject`, `preview`, `body` and `plain_text` are localisable fields;
`layout` is not, because which shell wraps a template is a structural decision, not
a translation.

## Brands

Unlike the other addons in this family, templates carry **no brand scope**. In a
multi-brand installation all brands share one set of templates. If you need
different shells per brand, model that with `layouts` rather than duplicating
templates.

## Blueprint and collection ownership

The addon owns the *existence* of the `et_templates` collection and its blueprint,
not their contents. Boot is a create-if-missing pass: once you edit the blueprint in
the CP — adding fields, reordering, renaming the collection — those edits stay. The
addon only writes when something is actually missing, and it will not overwrite
your changes.

The one thing it does reclaim: a collection carrying the placeholder front-end route
`_email-template-preview/{slug}` written by v1.2.1 and earlier has that route removed,
because `EmailTemplateEntry` replaced it. A route you set yourself is left alone.

If the addon cannot write to your content directory it logs a warning and carries on
rather than breaking the request. Check `storage/logs` if the collection does not
appear.

## Importing existing templates

If your emails currently live in files, import them once. Slugs are preserved 1:1,
so anything already referencing a template by slug keeps working:

```
php artisan email-templates:import --dry-run
php artisan email-templates:import
```

| Option | Effect |
|---|---|
| `--dry-run` | Report what would happen, write nothing |
| `--overwrite` | Replace entries whose slug already exists (default: skip) |
| `--source=` | Only import from the source with this label |

### Contributing an import source

Implement the contract and tag it — the command picks it up without any change here:

```php
use Goldnead\EmailTemplates\Contracts\EmailTemplateSource;

$this->app->tag([MySource::class], 'email-templates.sources');
```

## How Bard becomes email HTML

The body is stored as Bard (ProseMirror nodes) and rendered to HTML at send and
preview time by `BardHtmlRenderer` — one path, so the preview and the real email are
produced identically. Imported HTML is converted to Bard nodes by `HtmlToBard`.

**Fidelity note:** tiptap's schema keeps structural markup (headings, lists, links,
images, tables) and drops inline styles and unknown attributes. Simple transactional
templates round-trip cleanly; heavily styled legacy marketing HTML may lose styling.
Put the styling in a layout rather than in the body.

## Support

Only the latest release is supported, against Statamic 6. Bugs and questions go to
[GitHub issues](https://github.com/goldnead/statamic-email-templates/issues);
security reports go to the private channel in [SECURITY.md](SECURITY.md). Problems
with the Control Panel itself belong in [statamic/cms](https://github.com/statamic/cms/issues).

## Changelog · License

See [CHANGELOG.md](CHANGELOG.md) and [LICENSE.md](LICENSE.md) (commercial license).
