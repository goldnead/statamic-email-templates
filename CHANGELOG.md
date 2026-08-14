# Changelog

All notable changes to `statamic-email-templates` are documented here.

This file was reconstructed from the release tags on 2026-07-30; entries up to
1.2.1 are written from the tagged commits rather than recorded at the time.

## 2.1.0 — 2026-08-14

### Added — Vorlagen gehören zu einer Marke

Auf einer Mehrmarken-Installation zeigte die Liste jeder Marke die Vorlagen aller Marken. Auf dem
Hub hieß das: `?brand=gldnr-studio` und eine Liste FamilyStack-Mails. Der Markenumschalter änderte
die Kopfzeile und sonst nichts.

Die Ursache war nicht der Filter, sondern das fehlende Feld: dieses Addon kannte keine Marken.
Statamics Eintragsliste kennt Sites, keine Marken, und ein Slug ist die Adresse, nach der jede
Automation, jede Kampagne und jeder transaktionale Versand fragt — zwei Marken, die beide eine
`welcome` verschicken, brauchen zwei `welcome` und keinen Weg zueinander.

Neu ist deshalb ein Pflichtfeld `brand` im Blueprint, ein Filter auf der Liste (über Statamics
eigenen Haken `EntriesIndexQuery`, nicht über einen umgeschriebenen Controller) und die Auflösung
per Slug innerhalb der aktuellen Marke.

**Für Einmarken-Installationen ändert sich nichts.** `goldnead/statamic-brand-context` bleibt eine
weiche Abhängigkeit: kein Composer-Eintrag, kein Klassenname außerhalb von `Support\Brands`, und
ohne das Paket — oder mit ihm im Einmarken-Betrieb — ist das Blueprint Zeichen für Zeichen das
alte, ohne Feld, ohne Filter.

**Beim Umstieg** werden Vorlagen ohne Marke beim ersten Booten unter der **Standardmarke**
abgelegt, dieselbe Antwort, die die Migration von brand-context ihren Tabellen gegeben hat. Das
ist eine Vermutung, und sie ist auf dem Hub falsch: die sechs FamilyStack-Mails landen unter
`default`. Sie ohne Marke zu lassen wäre schlimmer — dann fände sie niemand mehr, in keiner Liste
und bei keinem Versand. Zum Geraderücken:

```
php please email-templates:assign-brand familystack --from=default --dry-run
php please email-templates:assign-brand familystack --from=default
```

Wo kein Versand seine Marke nennen kann (Konsole, Queue-Job außerhalb einer Marke), bleibt die
Auflösung per Slug ungefiltert statt leer: „kann die Marke nicht nennen" ist nicht dasselbe wie
„gehört zu keiner".

### Fixed — „Email Templates" stand zweimal in der Seitenleiste

Statamic listet jede Collection unter Content → Collections, und das Addon legt darüber hinaus
einen eigenen Menüpunkt an. Derselbe Schirm stand also zweimal im Menü, unter zwei Namen, und der
ungewollte saß zwischen den echten Collections der Seite, als wären E-Mail-Vorlagen Seiten. Der
automatische Eintrag wird jetzt entfernt.

## 1.3.1 — 2026-08-02
### Fixed — a cold Stache cache broke every read on the templates collection

1.3.0 gave the `et_templates` collection its own entry class, `EmailTemplateEntry`.
The Stache writes its items into the cache store, and Laravel reads that cache
back through `unserialize()` with an allowlist of classes
(`cache.serializable_classes`). Statamic registers its own classes there;
`EmailTemplateEntry` was not registered, so every cached template entry came back
as `__PHP_Incomplete_Class` and the first method call on it threw.

The failure was latent: as long as the cache still held entries written by 1.2.x,
nothing happened. It showed up on the first cold cache after the upgrade, which on
most sites is a `cache:clear` or a deploy.

How you recognise it:

- `php artisan statamic:stache:warm` aborts with `The script tried to call a
  method on an incomplete object … "Goldnead\EmailTemplates\Entries\EmailTemplateEntry"`,
  reported from `Stache/Stores/BasicStore.php`
- `Statamic\Jobs\HandleEntrySchedule` fails on every scheduler tick and piles up
  in `failed_jobs`
- the stack trace runs through the store twice, because the URI index reloads the
  same item while it is being read

The addon now adds its entry class to `cache.serializable_classes` in `register()`,
the way Statamic core does for its own classes. Sites that run without an
allowlist are left alone. After the update one `php artisan cache:clear` is
enough; no content changes.

## 1.3.0 — 2026-08-01
### Fixed — Live Preview no longer needs a fake front-end route

`EmailTemplateEntry` now exists. The README has promised it since 1.1: an entry
class that overrides `livePreviewUrl()` so the native Live Preview button appears
without the collection needing a front-end route. It was never written, so the
collection was instead given `_email-template-preview/{slug}` — a route that only
ever returned 404 and gave email templates a public URL they should not have.

On boot the addon sets `entryClass` and removes that placeholder route from
existing collections. A route you set yourself is left untouched.

### Fixed — a failing `ensure()` is logged instead of swallowed

`ensure()` writes into the site's own content directory on every boot, inside a
`catch (\Throwable)` with an empty body. A permissions problem, corrupt YAML or a
blueprint conflict made the addon silently do nothing. It now logs a warning with
the exception; boot still survives.

### Fixed — the test suite actually ran the addon

The hand-rolled Testbench case never registered the addon manifest, so Statamic's
`booted` callbacks never fired: `$commands`, `$routes`, views and translations
were wired by hand in the test and not at all the way they are in production.
Three import tests failed with `CommandNotFoundException` and one Live Preview
test failed outright. The suite now extends `Statamic\Testing\AddonTestCase`.

### Added — documentation a buyer can install from

The README covers requirements, installation, configuration, permissions,
multi-site, brand scope and blueprint ownership. Plus `LICENSE.md` (MIT, matching
`composer.json`), `SECURITY.md`, `.gitattributes`, GitHub Actions CI across the
PHP × Laravel range, Pint and Larastan.

### Major changes

- `EmailTemplateCollectionManager::FRONTEND_ROUTE` is now
  `LEGACY_FRONTEND_ROUTE` and is only used to recognise and remove the old route.
- `illuminate/console` and `illuminate/support` are constrained to `^12.40|^13.0`.
  The previous `^11.0` leg could never resolve — Statamic 6 requires
  `laravel/framework ^12.40 || ^13.0`.

## 1.2.1 — 2026-07-24

### Fixed — the preview put somebody's name in every install

The merge-variable fallback for `{{ sender.name }}` hardcoded `Adrian Goldner`.
Any install without a configured sender saw that name in its Live Preview.

Falls back to `config('app.name')`, then `Sender`, so the addon carries no
project-specific text. Part of decoupling the addon from the project it was
extracted from.

## 1.2.0 — 2026-07-19

### Added — a layout per template

An entry picks its layout from a `layout` select field, and the resolver wraps the
body in `config('email-templates.layouts')[handle]`.

Resolution order is layout field → `default_layout` → `branded_layout`, so an
install that sets none of them still renders a bare body exactly as before.
Threaded through `EmailTemplateData`, the resolver, `BrandedBodyRenderer` and Live
Preview, so the preview and the send path agree on which shell is used.

## 1.1.1 — 2026-07-19

### Fixed — links and underline were silently dropped

Both the Bard → HTML render and the HTML → Bard import used tiptap's StarterKit
alone, which does not register `Link` or `Underline`. Every `<a>` and `<u>`
disappeared: a CTA button survived the round-trip as plain text, and a send went
out with the link gone.

Silent in both directions, which is the worst property a render path can have —
nothing errored and the preview looked plausible.

`\Tiptap\Marks\Link` and `\Tiptap\Marks\Underline` are now registered.

## 1.1.0 — 2026-07-19

### Added — preview / preheader field

The short line most mail clients show next to the subject.

## 1.0.6 — 2026-07-19

### Added — branded body rendering

Wraps the Bard → HTML body in a host-supplied shell at the resolver's
choke-point, so both the Automations *Send Email Notification* action and Live
Preview get the same branding.

Opt-in through `branded_layout`; the default stays a raw body.

## 1.0.5 — 2026-07-19

### Fixed — SEO Pro fields on email templates

`et_templates` opts out via `seo: false` in the cascade. An email template is not
a page and had no business carrying meta fields.

## 1.0.4 — 2026-07-19

### Fixed — `Undefined array key` 500 on the preview target

A refresh key is passed with the preview target.

## 1.0.3 — 2026-07-19

### Fixed — native Live Preview never appeared

`Entry::livePreviewUrl()` is gated on the **collection having a route**, not on the
entry class. The `EmailTemplateEntry` override could therefore never work, and the
split-screen button stayed hidden.

The collection now has an internal route — the front end 404s, there is no
template — and the split screen renders through the preview target. The
non-working entry-class override was removed.

## 1.0.2 — 2026-07-19

### Changed — native Statamic Live Preview

Replaces the custom preview page with Statamic's own split-screen, live as you
type, inside the publish form.

## 1.0.1 — 2026-07-18

### Fixed — collection handle collided with host applications

Renamed the collection to `et_templates`. `email_templates` is a plausible handle
for a host application to have already, and the addon would have taken it over.

## 1.0.0 — 2026-07-18

Initial release.

- Native `et_templates` collection and blueprint: Title, Subject, Body (Bard),
  optional Plain text, Description. The **slug** is the stable, cross-addon
  reference.
- CP nav entry under Content.
- `email-templates:import` pulls file-based templates from sibling addons into
  entries, preserving slugs 1:1.
- `EmailTemplates::resolve($slug, $fallback)` — a managed entry wins, and the
  caller-supplied file fallback keeps un-migrated slugs working, so the addon can
  be added or removed without breaking a send.
