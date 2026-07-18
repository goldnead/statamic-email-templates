# Statamic Email Templates

CP-native, Bard-authored email templates in a shared Statamic collection.
`statamic-automations` and `statamic-marketing` consume it *optionally* — there
is no hard dependency in either direction.

## What it does

- Registers a native `email_templates` collection + blueprint (Title, Subject,
  Body as **Bard**, optional Plain text, Description). The slug is the stable,
  cross-addon reference.
- CP nav entry (Content section) pointing at the native collection listing.
- `email-templates:import` command pulls file-based templates from sibling
  addons (soft-dependency sources; marketing included) into entries, preserving
  the slug 1:1.
- `EmailTemplates::resolve($slug, $fallback)` — a managed entry wins; the
  caller-supplied file fallback keeps un-migrated slugs working.

## Bard → email HTML render path

The body is authored as Bard (ProseMirror nodes). `BardHtmlRenderer` renders
those nodes to email HTML at send/preview time (via tiptap-php, with the
Statamic Bard augmentor as fallback). Imported legacy HTML is converted to Bard
nodes by `HtmlToBard`.

**Fidelity note:** tiptap's default schema keeps structural markup (headings,
lists, links, images, tables) but drops inline styles / unknown attributes.
Simple transactional templates round-trip cleanly; heavily styled marketing HTML
may lose styling. If pixel-fidelity for complex templates is required, consider
`save_html: true` on the Bard field or a dedicated raw-HTML fallback field.

## Live Preview

The addon uses Statamic's **native Live Preview** (split-screen, live-as-you-type)
directly in the entry publish form. No separate preview page.

It is wired via two pieces set up automatically on boot:

- The `et_templates` collection gets a **preview target** pointing at a custom
  render route (`EmailTemplateCollectionManager::LIVE_PREVIEW_ROUTE`).
- Because the collection has no front-end route (email templates are not public
  pages), entries are instantiated as `EmailTemplateEntry`, which overrides
  `livePreviewUrl()` so the native Live Preview button still appears.

The render route (`GET /email-templates/live-preview`) resolves the live-edited,
unsaved entry from the Live Preview token
(`LivePreview::item($request->statamicToken())`) and renders it through the exact
same path as a real send — `BardHtmlRenderer` (Bard → HTML) → merge-variable
substitution — returning the merged subject and HTML body as the iframe contents.
The body only renders for a valid, short-lived Live Preview token; otherwise a
neutral placeholder is shown.

### Merge variables

Templates use `{{ dotted.key }}` placeholders in subject and body. Unknown tags
are left visible in the preview (so typos are obvious). The documented default
**sample** set (overridable via `config('email-templates.preview.sample_data')`):

| Variable                  | Sample value                     |
| ------------------------- | -------------------------------- |
| `{{ contact.first_name }}`| `Maria`                          |
| `{{ contact.last_name }}` | `Beispiel`                       |
| `{{ contact.full_name }}` | `Maria Beispiel`                 |
| `{{ contact.email }}`     | `maria.beispiel@example.com`     |
| `{{ contact.salutation }}`| `Hallo Maria`                    |
| `{{ sender.name }}`       | `config('mail.from.name')`       |
| `{{ sender.email }}`      | `config('mail.from.address')`    |
| `{{ unsubscribe_url }}`   | `https://example.com/newsletter/abmelden` |
| `{{ date }}`              | today, `d.m.Y`                   |

Substitution is centralised in `Support\MergeVariables::apply()`, so the send
path and the preview replace tags identically — only the supplied data differs.

## Usage from a sibling addon

```php
use Goldnead\EmailTemplates\Facades\EmailTemplates;

$template = EmailTemplates::resolve($slug, function (string $slug) {
    // return your addon's inline body as an array, or null
    return ['title' => '…', 'body' => '<p>…</p>'];
});

$template?->subject; // string
$template?->body;    // email-ready HTML string
```

## Contributing an import source

Implement `Goldnead\EmailTemplates\Contracts\EmailTemplateSource` and tag it:

```php
$this->app->tag([MySource::class], 'email-templates.sources');
```
