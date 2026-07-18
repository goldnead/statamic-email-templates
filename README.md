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

## CP preview

A **Vorschau** (Preview) entry action appears in the `email_templates` listing
row menu and the entry publish form; it opens the preview page for that
template. The page is also reachable from the nav (Content → E-Mail-Vorlagen →
Vorschau).

The preview renders through the exact same path as a real send —
`EmailTemplateResolver` → `BardHtmlRenderer` (Bard → HTML) → merge-variable
substitution — and shows the merged subject plus the HTML body in a **sandboxed
iframe** (`sandbox="allow-same-origin"`, no script execution) so email markup is
isolated from the CP.

### Preview endpoint

- `GET  cp/email-templates/preview/{id?}` — the preview page.
- `POST cp/email-templates/preview` — JSON render. Body: `template` (slug) **or**
  `id` (entry id), plus optional `merge_data` (overrides the sample set). Returns
  `{ slug, title, subject, body, source, merge_data }`. Both routes are CP-auth
  protected.

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
