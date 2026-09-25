# Changelog

## Unreleased

### Added: a registry for which mail goes out when

Addons announce their mails with `app('email-templates.registry')->register([...])`
(or the `EmailTemplateRegistry` facade): slug, sending addon, occasion, optional event
class, placeholders with labels and examples, and the shipped default text. The
template listing gains a **Sent on** column, the edit form lists the placeholders,
Live Preview and the test send fill in the examples, and `email-templates:import`
writes the defaults (`--locale=de|en` is new). Templates offered only through a
tagged import source show that source's label.

### Added: Statamic's and Laravel's account mails as templates

Opt-in via `core_mails.enabled` (default off, also on the settings screen). Password
reset (website and CP login), account activation / invitation, the elevated session
verification code and Laravel's `VerifyEmail` are sent from the published template
with the slugs `core-password-reset`, `core-password-reset-cp`,
`core-activate-account`, `core-verification-code` and `core-verify-email`. Without a
published template the core mail goes out unchanged. German and English defaults ship
with the package. Any error while building the template mail sends the core mail. A
host's `toMailUsing()` is respected (its button link is used, or its mail is left
alone). A CP reset by an Eloquent user uses the CP template. Every replacement fires
`CoreMailReplaced`; a listener that throws is reported, not raised. A host button is
only taken as the link when it can be one (reset: carries the token; verify: signed
or the core link). The CP says when a host `toMailUsing()` keeps a template from
taking effect.

### Changed

- Live Preview and the test send fill a registered template with its registered
  examples and `site_name` only, no longer with the generic `contact.*` set.
- `findBySlug()` prefers the current site's entry on a multi-site install.

## 2.7.1 — 2026-09-19

### Fixed: the snapshot's send time is shown in the display timezone

The heading over a snapshot preview — "Sent on …" — formatted in the **application's**
timezone. The heading over the report page that links to it formats in the browser's. On a host
whose `app.timezone` is UTC, the same send therefore appeared twice, two hours apart:
"Sent 18.9.2026, 20:03:50" above and "Sent on 18.09.2026, 18:03" below.

The obvious lever would have been to move `app.timezone`. It is the wrong one, and the
measurement says why: the timestamp columns hold UTC wall-clock with no timezone marker. Turning
the application's timezone does not make the display right — it makes every **already stored**
timestamp read two hours early, silently, with nothing anywhere going red. Measured on a staging
host on 19.09.2026: a campaign that went out at 20:03 Berlin sits in the column as
`2026-09-18 18:03:50`.

So storage stays UTC and the heading is formatted in `Statamic::displayTimezone()` — the knob
Statamic already ships for exactly this question. With no `statamic.system.display_timezone` set
it falls back to `app.timezone`, and nothing changes for installs that never had the problem.
Both halves are covered in `tests/Feature/SnapshotPreviewTest.php`.

### Fixed: static analysis can see this package's own views

`View::make('email-templates::branded', …)` failed Larastan's `view-string` check — not because
the view is missing, but because the analysis had no way to know it exists. Larastan asks the
real view finder (`view()->exists($literal)`), and in an application the namespace is there
because the service provider registered it with `loadViewsFrom()`. Analysing this package on its
own, no provider runs, so the finder has no hint for `email-templates::` and a correct literal
reads as a plain `string`.

That is a gap in the analysis setup, not a finding about the code, and `phpstan-bootstrap.php`
closes it by giving the finder the same hint the provider gives at runtime. The check is not
weakened — it now works at all: a typo in the view name fails the run, which was verified by
introducing one.

It surfaced now because CI installs with `composer update` rather than the lockfile and picked
up a newer Larastan that carries the `view-string` check. Locally green, in CI red.

## 2.7.0 — 2026-09-07

### New: the snapshot layer — what went out, once for the whole house

Marketing, notifications and automations are meant to show the mail on their
detail page, not only statistics. There is now exactly one place for that:
`Goldnead\EmailTemplates\Snapshots\Snapshots`, and behind it the table
`email_template_snapshots`.

**One row per send, not per recipient.** A campaign to 800 people is one row.
What is stored is the **template with its `{{ … }}` placeholders**, as it stood at
the time of sending: subject, body, plain text, layout reference, brand and
sender. Never the finished mail of a named person. Because no personal text goes
into it, this table needs no retention period and no deletion concept. The
recipients are not copied; they still hang on the tables of the sending addons.

The key is `(owner_type, owner_id, content_hash)`: the same sender with an
unchanged template gets the same row and a counter, a changed template a new one.
So an email node that fires ten thousand times produces exactly one row.

`record()` refuses content that carries a signed URL or a message tracking
pixel — that is a recipient's rendered mail and not the template. The send does
not abort over it; the refusal goes into the log.

Plus the view: `/cp/email-templates/snapshots/{id}/preview` shows a snapshot with
placeholder values. A consumer that wants to insert a particular contact's data of
today calls `SnapshotPreview::document()` with its own values. Both versions say
in the interface where the inserted values come from, and neither of them stores
the result.

### New: settings page

With `goldnead/statamic-brand-context` five keys are changeable per brand in the
Control Panel: `branded_layout`, `default_layout`, `snapshots.enabled`,
`test_send.subject_prefix` and `countdown.image`. New permission:
`manage email-templates settings`.

Not on the page: `enabled` (read at boot, so a switch there would take effect only
at the next deploy), `layouts` and `preview.sample_data` (tables, not values). The
group descriptions on the page say so.

Nobody holds the permission at first: until it is assigned to a role the section
stays invisible, even for users who may do everything else in this addon. Existing
permissions are unchanged.

`statamic-brand-context` stays a soft dependency — without the neighbour this page
does not register itself and the values stay in the config as before — but the
`suggest` now names a minimum version: **1.13 or later**. Older versions do carry
the page, but do not apply its values reliably. On an installation with a single
brand the settings of the addons registered last were not laid onto the config at
all, and up to 1.12 a second save of the same section deleted the first save's
override without a message. If you set values between 09-06 and this update, check
after updating whether they are still there.

### New: configuration

`snapshots.enabled` (default `true`).

## 2.6.1 — 2026-09-03

### Fixed: the Live Preview renders under the template's brand

Affects only installations with `goldnead/statamic-brand-context` and several
brands.

The preview ran under the brand the **request** resolved, and in the Control Panel
that is the brand of the signed-in person. Anyone opening a template of brand B as
a user of brand A saw its wording in A's identity: the sender name from
`{{ sender.name }}` belonged to A, and wherever the host app's shell reads the
brand, its colour did too. A convincing preview of the wrong mail, and nothing on
screen said so.

The render now runs inside `Brands::runFor()` under the entry's brand. The
viewer's brand context stands as it did before afterwards.

Four paths deliberately fall back to the previous behaviour: a template without a
brand, an installation without brand-context, a brand-context without `runFor`,
and a handle that no longer exists. The last case renders the mail without a brand
switch instead of letting the preview fail.

Found on `demo.adriangoldner.dev`. The regression test names the brand it was
rendered under instead of inferring it from a colour, and it is red against the
old code.

## 2.6.0 — 2026-09-03

> **Anyone who installed 2.5.0 should lift straight to this version.** 2.5.0 breaks
> an image address that contains a merge variable (`<img src="{{ hero_image }}">`).
> See below.

### Relative links become absolute, like images in 2.5.0

`<a href="/kurs">` has the same defect as a relative image: in the browser it
resolves against the website and in the mailbox against nothing. The reader clicks
and lands nowhere, without an error standing anywhere.

Left untouched are `mailto:`, `tel:`, everything else with a scheme, absolute and
protocol-relative addresses, and pure anchors (`#`). A `mailto:` rewritten to
`https://deine-seite.de/mailto:…` is a dead link in every program, and `href="#"`
is a deliberate non-link.

This runs before `statamic-automations` rewrites links for LeadHub click tracking.
That is the right order: that rewriter needs a real URL.

### Fixed: 2.5.0 destroyed addresses with merge variables

The absolutising from 2.5.0 ran over **every** image address, including one that
still contained a merge variable. The substitution happens only afterwards, so at
that point the `src` literally held `{{ hero_image }}`. `url()` encodes the curly
braces as `%7B%7B`, the later replacement no longer finds its pattern, and the
recipient gets an address named after the variable instead of after the image.

An address that still carries a `{{ … }}` is now left alone — image as well as
link. An unsubscribe link has exactly this shape, so that is the normal case and
not a special one. Whoever uses a variable in an address puts an absolute URL in
there.

## 2.5.0 — 2026-09-03

### Images in email templates work

An `<img>` in a template's body did not reach the recipient. It was lost in two
places independently of each other: `HtmlToBard` parsed HTML with a tiptap schema
without an `image` node, so an image never became a node on import, and
`BardHtmlRenderer` renders an `image` node it received anyway as an empty string.
Neither place reported anything. `HtmlToBard`'s docblock expressly listed "images"
as preserved, which is why it could not be found by reading the code.

Both directions now share one extension list, `TiptapExtensions`. A node added
there applies to import and to output at once — they cannot drift apart again.

Plus two things without which an image in a mail only half works:

- **Relative image paths become absolute.** A Statamic asset stands in the field
  as `/assets/flyer.png`. In the browser that resolves against the website and in
  the mailbox against nothing — the recipient sees a broken image without an error
  standing anywhere. Applies to the raw-text path as well, which imported legacy
  templates go through. Absolute, protocol-relative, `data:` and `cid:` sources
  stay untouched.
- **Every image gets `max-width:100%;height:auto;border:0;`** as an inline style.
  Without `max-width` a 1200px header graphic forces horizontal scrolling in a
  phone client. `border` stands in the style and not as `border="0"`, because
  tiptap-php cannot output an attribute with the value `0` at all:
  `HTML::renderAttributes()` sends the attribute array through `array_filter()`
  without a callback, and `'0'` is falsy in PHP.

The test send's empty-body refusal now counts an image as content. In 2.4.0 it was
right by accident, because nothing arrived anyway.

**Tables stay out.** The same docblock claimed them too, and that was not true
either. But a table is not a single node, it is four, and an email table wants
`cellpadding`, `cellspacing` and `role="presentation"`, which tiptap does not
output. The docblock now says so.

## 2.4.0 — 2026-09-03

### Send a template to a real inbox

New Control Panel action **Send test email**, in the row menu of the listing and
in the publish form's action menu. It asks for an address (prefilled with the
logged-in user's) and sends the saved template there.

Until now the only way to see a template in an actual mail client was to trigger
the real thing — make a purchase, fire an automation. Live Preview shows the mail
in a browser, and a browser is not a mail client: Outlook lays out with Word,
Gmail drops the `<style>` block. The split-screen could never answer whether a
template survives the trip.

The test takes the real send path. `EmailTemplateResolver` gained `forEntry()`,
which shares its new `decorate()` step with `resolve()`, so preheader injection
and layout wrapping happen in one place for both the test and the automations
send node. Merge variables are filled from `preview.sample_data`, the same set
the Live Preview uses. The From is the address the preview shows.

- Not queued. A queued test would report success from the moment the job was
  written, and never arrive on a host without a worker.
- A refusing mailer produces a **red** toast naming the reason, not a green
  "sent". An exception out of an action's `run()` is toasted green by core, so
  the failure travels as a server-pushed toast with `message: false` beside it.
- A template with an empty body is refused with a message saying so, rather than
  sending a blank mail that looks like a mailer fault.
- Permitted by `edit et_templates entries`. No new permission — a new one would
  be off for every existing role, hiding the button from the people who write the
  templates.

New config key `test_send.subject_prefix` (default `'[Test] '`); set it to an
empty string to send the subject exactly as a recipient sees it.

`MergeVariables::previewSender()` is now public, so the test send can use the
same From the preview promises.

### Known gap

An image-only body cannot be sent, and the empty-body refusal is what you get.
`HtmlToBard` drops `<img>` on import despite its docblock saying it keeps images,
and `BardHtmlRenderer` renders a ProseMirror `image` node as the empty string.
Pre-existing, not introduced here, and now covered by a test that fails when it
is fixed.

> Fixed in 2.5.0, on the same day.

## 2.3.0 — 2026-09-02

> **Anyone using `goldnead/statamic-funnels` lifts it to 1.9.1 together with this version.**
> From this version on, `MergeVariables::apply()` escapes the inserted values. funnels 1.9.0
> and older already hands its order lines in as finished markup and its subject without a
> switch; with 2.3.0 alone the mail would then read `&amp;lt;br&amp;gt;` instead of a line
> break. 1.9.1 names its own raw variable and sends the subject unprotected. The two versions
> belong in the same step.

### Added — a countdown in a mail

Two new tags for launch mails (course start, registration deadline), resolved by
the same `MergeVariables::apply()` pass as every other variable, so they are the
same in the Live Preview and when sending:

- `{{ countdown until="2026-10-01 18:00" }}` writes, at render time,
  "3 days, 4 hours left (Oct 1, 2026, 18:00)". Time zone from `app.timezone`,
  German and English by app locale, `format="relative|absolute|both"`,
  `expired="…"` for the text after expiry (default "over"). `until` may be a
  variable: `until="{{ event.starts_at }}"` or `until="event.starts_at"`. An
  `until` that cannot be resolved leaves the tag standing, like any unknown
  variable. **This is the version for nine cases out of ten:** no image, no route,
  works in every client.
- `{{ countdown_image until="…" width="480" }}` renders an `<img>` onto the new
  signed route `GET /!/statamic-email-templates/countdown.png` (query `until`,
  `w`, `bg`, `fg`, `label`, `expired`; `throttle:60,1`;
  `Cache-Control: max-age=60`). GD draws "dd : hh : mm" as a seven-segment
  display, after expiry `00 : 00 : 00` plus "over". Without `ext-gd`, or with
  `email-templates.countdown.image => false`, the route answers 404 and writes a
  warning to the log. Without a valid signature, 403.

The README says what the image buys you: Gmail fetches it afresh through its proxy
on every open (and every open is a request to the server), Apple Mail Privacy
Protection fetches it once in advance and afterwards shows that state permanently.

For it came `Support\Countdown`, `Support\FunctionTags` (the second, narrow pass
for tags with parameters, after the simple `{{ dotted.key }}`),
`Support\CountdownImage`, `Http\Controllers\CountdownImageController`,
`routes/actions.php`, the language file `countdown.php` (de/en) and the config key
`countdown.image`.

### Fixed — inserted values landed raw in the mail's HTML

`MergeVariables::apply()` inserted every supplied value unchanged. A name from a
form with a `<script>` in it thereby became markup in a mail — the same class of
bug that was fixed in `statamic-payments` on the same day. The README named that
expressly as a property ("inserted verbatim"); that was a promise nobody could
keep, because the sending addon does not know the HTML context its value falls
into.

Scalars are now escaped with `e()` when inserted. Two exceptions, both named
rather than silent:

- **`MergeVariables::RAW_VARIABLES`** — today `unsubscribe_url`, an address of
  this package that is needed as an `href`. Only the keys **this** package
  supplies stand here: a name here is raw for every consumer, including those that
  never escaped it.
- **`apply($text, $data, raw: ['order.lines'])`** — for keys the caller supplies
  itself and that already carry markup. `statamic-funnels` builds `order.lines`
  from `e()`-escaped parts with `<br>` between them, because a list of order lines
  without separator markup does not make it into an HTML mail. The caller names
  its key per call instead of making it raw for everybody, and escaping still
  happens exactly once per value. Passed positionally, not by name: a consumer
  still running against 2.2.x ignores additional arguments silently, while an
  unknown named argument would be fatal.
- **`apply($text, $data, escape: false)`** — for outputs that are not HTML:
  subject line and plain-text part. There an `&amp;` would be visible damage
  rather than protection. The Live Preview calls it that way for subject and
  preheader: both are already escaped once at their point of output
  (`htmlspecialchars` in the preview document and in `EmailPreheader::html()`
  respectively); a second time would give `&amp;amp;`.

The order in `apply()` stays: first the simple `{{ dotted.key }}` with escaping,
then `FunctionTags`. So the `<img>` from `{{ countdown_image }}` comes into being
**after** the escaping and escapes its own attributes, and therefore stays an
`<img>`.

**For callers:** anyone using `apply()` for a subject line today has to add
`escape: false`, otherwise the subject reads `&amp;`.

## 2.2.0 — 2026-08-24

### Fixed — the preview showed every brand the same sender

`MergeVariables` resolved `{{ sender.name }}` and `{{ sender.email }}` from
`config('mail.from.*')`. On a host with several brands that meant every template
was previewed with the same sender, and for all brands but one it was the wrong
one. **Nothing was ever sent that way** — the preview is a path of its own — but a
preview whose sender is a lie is no use for the one thing it is opened for.

Where `statamic-brand-context` is available, the sender now comes from the current
brand. **The coupling is optional** (`class_exists`, plus a `suggest` entry): this
package does not require brand-context, and an installation without it behaves
unchanged.


All notable changes to `statamic-email-templates` are documented here.

This file was reconstructed from the release tags on 2026-07-30; entries up to
1.2.1 are written from the tagged commits rather than recorded at the time.

## 2.1.2 — 2026-08-14

### Fixed — retrofitting the brand field overwrote the whole blueprint

On the upgrade to 2.1.x the blueprint was rewritten completely instead of only
inserting the missing field. But the blueprint lies in the site's `resources/` and
is allowed to have been edited there — reordered fields, changed instructions, a
readably named `layout` select field. All of that came back as the package
default.

That is exactly what happened on the hub: the layout option
`FamilyStack (Paper-Craft)` became `Familystack`, the name generated from the
handle. Nothing failed, nobody got a message, and nobody looks into that file that
day — this is the kind of upgrade that is worse than one that does nothing at all.

The field is now **inserted** at the end of the first section, and the rest of the
file stays as the site has it. Two tests hold that in place: a field added by hand
survives the upgrade, and three boots in a row create the brand field exactly
once.

## 2.1.1 — 2026-08-14

### Fixed — 2.1.0 came out of an outdated copy and was still MIT

The brand work from 2.1.0 was built on a local `main` that was missing the two
commits from 2.0.0 — the licence switch to proprietary and its CHANGELOG entry.
The tag therefore points at a state whose `composer.json` and licence file still
say MIT.

Nothing about the addon's code changes between 2.1.0 and 2.1.1: identical classes,
identical tests. What is added is the merge with 2.0.0.

`v2.1.0` stays where it is. A published version is immutable, and Packagist
correctly refused to move the tag — the way to do this is a new version, not a
moved tag.

## 2.1.0 — 2026-08-14

### Added — templates belong to a brand

On a multi-brand installation the listing showed every brand the templates of all brands. On the
hub that meant `?brand=gldnr-studio` and a list of FamilyStack mails. The brand switcher changed
the header and nothing else.

The cause was not the filter but the missing field: this addon knew no brands. Statamic's entry
listing knows sites, not brands, and a slug is the address every automation, every campaign and
every transactional send asks for — two brands that both send a `welcome` need two `welcome`s and
no way from one to the other.

New is therefore a required `brand` field in the blueprint, a filter on the listing (through
Statamic's own hook `EntriesIndexQuery`, not through a rewritten controller) and resolution by
slug within the current brand.

**For single-brand installations nothing changes.** `goldnead/statamic-brand-context` stays a
soft dependency: no Composer entry, no class name outside `Support\Brands`, and without the
package — or with it in single-brand operation — the blueprint is character for character the old
one, without the field, without the filter.

**On migrating**, templates without a brand are filed under the **default brand** at the first
boot, the same answer brand-context's migration gave its own tables. That is a guess, and on the
hub it is the wrong one: the six FamilyStack mails land under `default`. Leaving them without a
brand would be worse — then nobody would find them at all any more, in no listing and in no send.
To put it straight:

```
php please email-templates:assign-brand familystack --from=default --dry-run
php please email-templates:assign-brand familystack --from=default
```

Where no send can name its brand (console, a queue job outside a brand), resolution by slug stays
unfiltered rather than empty: "cannot name the brand" is not the same as "belongs to none".

### Fixed — "Email Templates" stood twice in the sidebar

Statamic lists every collection under Content → Collections, and on top of that the addon creates
a nav item of its own. So the same screen stood twice in the menu, under two names, and the
unwanted one sat among the site's real collections, as if email templates were pages. The
automatic entry is now removed.

## 2.0.0 — 2026-08-09

### Changed — the licence is now proprietary

This is a paid Marketplace addon. `composer.json` declares `proprietary` and the
licence file carries the commercial addon licence instead of MIT. Entitlement is
enforced by the Statamic Marketplace, not by code in this package.

Tags up to and including `v1.3.1` remain MIT. The change takes effect with the next
release.

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
