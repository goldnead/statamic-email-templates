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

// The body is HTML: values are escaped.
$html = MergeVariables::apply($template->body, $data);

// The subject is not HTML: ask for raw output, or the reader sees `&amp;`.
$subject = MergeVariables::apply($template->subject, $data, escape: false);
```

Unknown tags are **left visible** rather than silently removed, so a typo shows up
in the preview instead of in someone's inbox.

**Values are HTML-escaped on the way in.** A merge value is recipient data — a name
from a signup form — and a name containing `<script>` belongs in the mail as text,
not as markup. Three exceptions:

- Keys named in `MergeVariables::RAW_VARIABLES` are inserted raw. Today that is
  `unsubscribe_url`, an address this package builds and that is used as an `href`.
- `escape: false` turns escaping off for the whole call. Use it for output that is
  not HTML: the subject line and a plain-text part.
- `raw: ['order.lines']` names **your own** keys that already carry markup, for this
  call only:

  ```php
  $html = MergeVariables::apply($template->body, $data, raw: ['order.lines']);
  ```

  Escape the parts yourself before you join them. Your key stays out of
  `RAW_VARIABLES`, where it would be raw for every other consumer too.

`{{ countdown_image }}` emits an `<img>` of its own. It is resolved after the
escaping pass and escapes its own attributes, so its markup arrives intact.

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

### Test send

Live Preview renders the mail in a browser, and a mail client is not a browser:
Outlook lays out with Word, Gmail drops the `<style>` block, a dark-mode client
repaints colours nobody chose. So the split-screen answers *did I write what I
meant* and cannot answer *does it survive the trip*.

**Send test email** does. It sits in the row menu of the listing and in the
action menu of the publish form, asks for an address (prefilled with your own),
and sends the template to it.

What arrives is what a recipient would get. The action renders through
`EmailTemplateResolver::forEntry()`, which shares its one `decorate()` step with
the `resolve()` a sending addon calls — same preheader injection, same layout
wrapping, same `MergeVariables::apply()`. Merge variables are filled with the
documented sample data from `preview.sample_data`, so `{{ contact.first_name }}`
arrives as *Maria*, not as a raw tag. The From is the address the Live Preview
shows.

Three things worth knowing:

- **It sends the saved entry**, not your unsaved edits. Save first.
- **It is not queued.** A queued test would report success the moment the job was
  written, and on a host with no worker it would never arrive.
- **A failure is red.** A mailer that refuses — wrong credentials, a throttled
  relay, a From the provider rejects — produces an error toast naming the reason,
  never a green "sent" over a mail that never left.

Whoever may edit a template may test it (`edit et_templates entries`); the addon
adds no permission of its own.

## Configuration

`config/email-templates.php`:

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` | Master switch. `false` stops the addon creating the collection and adding the nav item. The resolver and the import command stay callable. |
| `branded_layout` | `null` | A Blade view that wraps every rendered body — your header, footer and styling. It must contain `@yield('content')`; the subject arrives as `$title`. `null` renders bodies unwrapped. |
| `layouts` | `[]` | A `handle => view` map. The keys populate the `layout` select on each entry, so a transactional mail can pick a lean shell and a campaign a marketing one. |
| `default_layout` | `null` | A handle from `layouts`, used for entries that pick none. |
| `preview.sample_data` | see above | Deep-merged over the built-in merge-variable sample set. |
| `test_send.subject_prefix` | `'[Test] '` | Put in front of the subject of a test send, so a test is recognisable in an inbox that also holds real mail. Empty string sends the subject exactly as a recipient would see it. |
| `snapshots.enabled` | `true` | Whether a send is recorded — see [Send snapshots](#send-snapshots). `false` stops recording; rows already written stay readable. |
| `core_mails.enabled` | `false` | Send Statamic's and Laravel's account mails from templates — see [Core account mails](#core-account-mails). |

Layout resolution for an entry: its own `layout`, else `default_layout`, else
`branded_layout`. An unknown handle or a missing view falls through the chain —
nothing throws mid-send.

### Settings screen

With `goldnead/statamic-brand-context` installed, six of these keys are editable
per brand under **Control Panel → Settings**: `branded_layout`, `default_layout`,
`snapshots.enabled`, `test_send.subject_prefix`, `countdown.image` and
`core_mails.enabled`. Only keys
somebody actually changed are stored; everything else keeps following the config
file, so upgrading the package still moves the defaults.

`enabled`, `layouts` and `preview.sample_data` are deliberately **not** on that
screen. `enabled` is read while the addon boots, and the settings layer applies
its overrides afterwards — a switch there would only take effect on the next
deploy. The other two are maps, not values: editing them means adding and
removing rows, and a removed layout handle strands every template that chose it.

## Send snapshots

What actually went out, held once for the whole suite. One row per send — a
campaign to 800 people is one row — carrying the **template with its `{{ … }}`
placeholders intact**, never the rendered mail of a named person. Nothing
personal is stored, so the table needs no retention rule and no deletion concept.
Recipients are not copied either: they already exist on the sending addon's own
tables.

Consumers (`statamic-marketing`, `statamic-notifications`,
`statamic-automations`) record a send and show it again:

```php
private const SNAPSHOTS = 'Goldnead\\EmailTemplates\\Snapshots\\Snapshots';

// At send time, once — with the template, not with one recipient's mail.
if (class_exists(self::SNAPSHOTS)) {
    $class = self::SNAPSHOTS;

    $snapshot = $class::record('marketing:campaign', $campaign->id, [
        'subject' => $campaign->subject,
        'body' => $templateHtml,
        'slug' => $campaign->templateHandle,
    ]);
}

// On the detail page — an iframe and nothing else.
$url = $class::previewUrl($snapshot);
```

The key is `(owner_type, owner_id, content_hash)`: sending an unchanged template
again reuses the row and counts it, sending an edited one writes a new row. An
e-mail node that fires ten thousand times is one row.

`record()` refuses content carrying a signed URL or a per-message tracking pixel,
because that is the rendered mail of one recipient rather than the template.

## Permissions

- `manage email-templates settings` — the addon's section on the shared settings
  screen. Nothing else in the addon checks it.

Everything about the templates themselves is governed by the collection's native
permissions, which Statamic generates:

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
| `--locale=` | Language of the shipped default texts (`de`, `en`); default is the app locale |

### Contributing an import source

Implement the contract and tag it — the command picks it up without any change here:

```php
use Goldnead\EmailTemplates\Contracts\EmailTemplateSource;

$this->app->tag([MySource::class], 'email-templates.sources');
```

A tagged source only feeds the import. To also tell editors *when* a mail goes out,
register it (next section); a registered default is imported the same way.

## Registering an addon's mails

An addon that sends mail announces each template once, in its provider's `boot()`:

```php
if (app()->bound('email-templates.registry')) {
    app('email-templates.registry')->register([
        'slug' => 'teams-invitation',              // what the addon resolves
        'addon' => 'Teams',                        // who sends it
        'trigger' => fn () => __('teams::mail.invitation.trigger'), // on which occasion
        'event' => \Goldnead\Teams\Events\InvitationSent::class,    // optional
        'placeholders' => [
            'team.name' => ['label' => 'Name of the team', 'example' => 'Sopranos'],
            'url' => 'Link to accept the invitation',
        ],
        'defaults' => fn () => [
            'title' => 'Team invitation',
            'subject' => 'Join {{ team.name }}',
            'preview' => '…',
            'body' => '<p>…<a href="{{ url }}">Accept</a></p>',
        ],
    ]);
}
```

Plain arrays and closures only, so the addon needs no class from this package and
keeps it a `suggest`. Closures are called when read, in the locale of the request
that reads them. The facade `Goldnead\EmailTemplates\Facades\EmailTemplateRegistry`
offers the same methods (`register`, `find`, `all`, `byAddon`, `describe`, `examples`).

What registering buys:

- **"Sent on" in the listing and on the edit form**: `Teams: Jemand wird in ein Team eingeladen`.
  A template no addon claims shows nothing there; one that only comes from a tagged
  import source shows the source's label.
- **The placeholder list** on the edit form's sidebar.
- **Examples in Live Preview and the test send**, so a preview shows a link, not `{{ url }}`.
- **`email-templates:import`** writes the `defaults` (`--source=<addon>`).

Both extra fields are added to the blueprint in memory on Control Panel requests,
the way core adds its `site` column. Nothing is written into the site's blueprint file.

## Core account mails

Statamic and Laravel send a few account mails of their own. With
`core_mails.enabled` on, each is sent from the template with its slug instead:

| Slug | Replaces | Sent when |
|---|---|---|
| `core-password-reset` | `Statamic\Notifications\PasswordReset`, `Illuminate\Auth\Notifications\ResetPassword` | Someone asks for a new password on the website |
| `core-password-reset-cp` | `Statamic\Notifications\PasswordReset` from the CP login screen | Someone asks for a new password on the CP login screen |
| `core-activate-account` | `Statamic\Notifications\ActivateAccount` | A new account's activation / invitation goes out |
| `core-verification-code` | `Statamic\Notifications\ElevatedSessionVerificationCode` | Someone has to confirm who they are (elevated session) without a two-factor app |
| `core-verify-email` | `Illuminate\Auth\Notifications\VerifyEmail` | A `MustVerifyEmail` account confirms its address |

Placeholders: `{{ url }}`, `{{ user.name }}` (the address when there is no name),
`{{ user.email }}`, `{{ site_name }}`, `{{ expires_in }}` ("1 hour"),
`{{ expires_minutes }}`; `{{ message }}` for the activation (the text an admin typed
into the CP's invite form), `{{ code }}` for the verification code. An invitation
subject typed in the CP beats the template's subject.

Nothing changes unless **all** of these hold: the setting is on, the template exists
and it is **published**. A draft is how you prepare a mail without it going live.
If a template fails to render, the core mail goes out and a warning is logged.

The link is computed by the code core uses, so `PasswordReset::resetFormUrl()`,
`ResetPassword::createUrlUsing()`, `VerifyEmail::createUrlUsing()`, the CP's own
reset form and a `web`/`cp` broker split in `statamic.users.passwords` keep working.
Statamic's Eloquent users hand the reset to the model when it defines
`sendPasswordResetNotification()` (Laravel's `CanResetPassword` does); that sends
Laravel's `ResetPassword`, which is covered too.

How: a `NotificationSending` listener renders the template, sends it as
`Goldnead\EmailTemplates\CoreMails\TemplatedCoreMail` (its `replaces` property names
the original class) and cancels the original on the mail channel.

```
php please email-templates:import --source=Statamic --locale=de
```

writes German defaults for all five (`--locale=en` for English). A plain
`email-templates:import` leaves them out while the setting is off.

## How Bard becomes email HTML

The body is stored as Bard (ProseMirror nodes) and rendered to HTML at send and
preview time by `BardHtmlRenderer` — one path, so the preview and the real email are
produced identically. Imported HTML is converted to Bard nodes by `HtmlToBard`.

Both directions share one tiptap extension list, `TiptapExtensions`. A node added
there is added to the parse and the render side at once, which is what keeps them
from disagreeing about what a template may contain.

**Fidelity note:** the schema keeps headings, lists, links and images, and drops
inline styles and unknown attributes. **Tables are not kept.** Simple transactional
templates round-trip cleanly; heavily styled legacy marketing HTML may lose styling.
Put the styling in a layout rather than in the body.

### Addresses: images and links

An address in an email body is resolved by a machine that knows nothing about the
site that sent it. `/assets/flyer.png` and `/kurs` resolve against your site in a
browser and against **nothing at all** in an inbox: the reader sees a broken image
or clicks into the void, and nothing is logged anywhere.

So every relative `<img src>` and `<a href>` is made absolute against your
`app.url` — set it correctly. Left exactly as written:

| Kind | Example | Why |
|---|---|---|
| Carries a merge tag | `{{ unsubscribe_url }}` | Substitution happens *after* rendering. Encoding the braces would break it. |
| Has a scheme | `https:`, `mailto:`, `tel:`, `data:`, `cid:` | A rewritten `mailto:` is a dead link in every client. |
| Protocol-relative | `//cdn.example/x.png` | Already absolute enough. |
| Fragment | `#`, `#abschnitt` | `href="#"` is a deliberate non-link. |

If you use a merge variable in an address, put an **absolute** URL in the variable.

This runs before `statamic-automations` rewrites links for LeadHub click tracking,
which is the order that rewriter needs.

Images additionally get **`max-width:100%;height:auto;border:0;`** inline. Without
`max-width` a 1200px header graphic forces a sideways scroll in a phone client that
ignores the viewport. `border` rides in the style rather than as `border="0"`
because tiptap-php cannot emit an attribute whose value is `0`.

Images are referenced by URL, not attached — mail clients fetch them, and many block
that until the reader allows it. Give every image a meaningful `alt`.

## Support

Only the latest release is supported, against Statamic 6. Bugs and questions go to
[GitHub issues](https://github.com/goldnead/statamic-email-templates/issues);
security reports go to the private channel in [SECURITY.md](SECURITY.md). Problems
with the Control Panel itself belong in [statamic/cms](https://github.com/statamic/cms/issues).

## Changelog · License

See [CHANGELOG.md](CHANGELOG.md) and [LICENSE.md](LICENSE.md) (commercial license).
