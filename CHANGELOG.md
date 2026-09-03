# Changelog

## 2.6.1 — 2026-09-03

### Behoben: die Live-Vorschau rendert unter der Marke der Vorlage

Betrifft nur Installationen mit `goldnead/statamic-brand-context` und mehreren
Marken.

Die Vorschau lief unter der Marke, die der **Request** aufgelöst hat, und das ist
im Control Panel die Marke der angemeldeten Person. Wer eine Vorlage der Marke B
als Nutzer der Marke A öffnete, sah deren Wortlaut in der Identität von A: der
Absendername aus `{{ sender.name }}` gehörte A, und wo die Hülle der Host-App die
Marke liest, auch deren Farbe. Eine überzeugende Vorschau der falschen Mail, und
nichts auf dem Schirm sagte es.

Der Render läuft jetzt in `Brands::runFor()` unter der Marke des Eintrags. Der
Markenkontext des Betrachters steht danach wieder wie vorher.

Vier Wege fallen bewusst auf das bisherige Verhalten zurück: eine Vorlage ohne
Marke, eine Installation ohne brand-context, ein brand-context ohne `runFor`, und
ein Handle, den es nicht mehr gibt. Der letzte Fall rendert die Mail ohne
Markenwechsel, statt die Vorschau scheitern zu lassen.

Gefunden auf `demo.adriangoldner.dev`. Der Regressionstest nennt die Marke, unter
der gerendert wurde, statt sie aus einer Farbe zu schließen, und ist gegen den
alten Code rot.

## 2.6.0 — 2026-09-03

> **Wer 2.5.0 installiert hat, hebt direkt auf diese Fassung.** 2.5.0 macht eine
> Bildadresse kaputt, die eine Merge-Variable enthält (`<img src="{{ hero_image }}">`).
> Siehe unten.

### Relative Links werden absolut, wie Bilder in 2.5.0

`<a href="/kurs">` hat denselben Defekt wie ein relatives Bild: es löst im
Browser gegen die Website auf und im Postfach gegen nichts. Der Leser klickt und
landet im Leeren, ohne dass irgendwo ein Fehler steht.

Unangetastet bleiben `mailto:`, `tel:`, alles andere mit Schema, absolute und
protokollrelative Adressen sowie reine Anker (`#`). Ein `mailto:`, das zu
`https://deine-seite.de/mailto:…` umgeschrieben wird, ist in jedem Programm ein
toter Link, und `href="#"` ist ein absichtlicher Nicht-Link.

Das läuft, bevor `statamic-automations` Links für die LeadHub-Klickverfolgung
umschreibt. Das ist die richtige Reihenfolge: dieser Umschreiber braucht eine
echte URL.

### Behoben: 2.5.0 zerstörte Adressen mit Merge-Variablen

Das Absolutmachen aus 2.5.0 lief über **jede** Bildadresse, auch über eine, die
noch eine Merge-Variable enthielt. Die Substitution passiert erst danach, also
stand zu diesem Zeitpunkt buchstäblich `{{ hero_image }}` im `src`. `url()`
kodiert die geschweiften Klammern zu `%7B%7B`, die spätere Ersetzung findet ihr
Muster nicht mehr, und der Empfänger bekommt eine Adresse, die nach der Variablen
benannt ist statt nach dem Bild.

Eine Adresse, die noch ein `{{ … }}` trägt, wird jetzt in Ruhe gelassen — Bild
wie Link. Ein Abmeldelink hat genau diese Form, das ist also der Normalfall und
kein Sonderfall. Wer eine Variable in einer Adresse benutzt, gibt dort eine
absolute URL hinein.

## 2.5.0 — 2026-09-03

### Bilder in E-Mail-Vorlagen funktionieren

Ein `<img>` im Text einer Vorlage kam beim Empfänger nicht an. Es ging an zwei
Stellen unabhängig voneinander verloren: `HtmlToBard` parste HTML mit einem
tiptap-Schema ohne `image`-Node, also wurde ein Bild beim Import nie zu einem
Knoten, und `BardHtmlRenderer` rendert einen `image`-Knoten, den er trotzdem
bekam, als Leerstring. Keine der beiden Stellen meldete etwas. Der Docblock von
`HtmlToBard` führte „images" ausdrücklich als erhalten auf, weshalb es beim
Lesen des Codes nicht zu finden war.

Beide Richtungen teilen sich jetzt eine Erweiterungsliste, `TiptapExtensions`.
Ein Knoten, der dort dazukommt, gilt für Import und Ausgabe zugleich — sie
können nicht wieder auseinanderlaufen.

Dazu zwei Dinge, ohne die ein Bild in einer Mail nur halb funktioniert:

- **Relative Bildpfade werden absolut.** Ein Statamic-Asset steht als
  `/assets/flyer.png` im Feld. Das löst im Browser gegen die Website auf und im
  Postfach gegen nichts — der Empfänger sieht ein kaputtes Bild, ohne dass
  irgendwo ein Fehler steht. Gilt auch für den Rohtext-Pfad, über den importierte
  Alt-Vorlagen laufen. Absolute, protokollrelative, `data:`- und `cid:`-Quellen
  bleiben unangetastet.
- **Jedes Bild bekommt `max-width:100%;height:auto;border:0;`** als Inline-Style.
  Ohne `max-width` erzwingt eine 1200px-Kopfgrafik in einem Handy-Client
  Querscrollen. `border` steht im Style und nicht als `border="0"`, weil
  tiptap-php ein Attribut mit dem Wert `0` gar nicht ausgeben kann:
  `HTML::renderAttributes()` schickt das Attribut-Array durch `array_filter()`
  ohne Callback, und `'0'` ist in PHP falsy.

Die Leer-Body-Absage des Testversands zählt ein Bild jetzt als Inhalt. In 2.4.0
war sie zufällig richtig, weil ohnehin nichts ankam.

**Tabellen bleiben draußen.** Derselbe Docblock behauptete sie auch, und auch
das stimmte nicht. Eine Tabelle ist aber kein einzelner Knoten, sondern vier,
und eine E-Mail-Tabelle will `cellpadding`, `cellspacing` und
`role="presentation"`, die tiptap nicht ausgibt. Der Docblock sagt das jetzt.

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

> Behoben in 2.5.0, am selben Tag.

## 2.3.0 — 2026-09-02

> **Wer `goldnead/statamic-funnels` einsetzt, hebt es zusammen mit dieser Fassung auf 1.9.1.**
> Seit dieser Fassung escaped `MergeVariables::apply()` die eingesetzten Werte. funnels 1.9.0
> und älter reicht seine Bestellzeilen bereits als fertiges Markup herein und seinen Betreff
> ohne Schalter; mit 2.3.0 allein stünde in der Mail dann `&amp;lt;br&amp;gt;` statt eines
> Zeilenumbruchs. 1.9.1 benennt seine eigene Roh-Variable und schickt den Betreff ungeschützt.
> Die beiden Fassungen gehören in denselben Schritt.

### Added — Countdown in einer Mail

Zwei neue Tags für Launch-Mails (Kursstart, Anmeldeschluss), aufgelöst vom
selben `MergeVariables::apply()`-Durchlauf wie alle anderen Variablen, also in
der Live-Vorschau und beim Versand gleich:

- `{{ countdown until="2026-10-01 18:00" }}` schreibt zum Renderzeitpunkt
  „noch 3 Tage, 4 Stunden (01.10.2026, 18:00 Uhr)". Zeitzone aus `app.timezone`,
  Deutsch und Englisch nach App-Locale, `format="relative|absolute|both"`,
  `expired="…"` für den Text nach Ablauf (Standard „vorbei"). `until` darf eine
  Variable sein: `until="{{ event.starts_at }}"` oder `until="event.starts_at"`.
  Ein `until`, das sich nicht auflösen lässt, lässt den Tag stehen, wie jede
  unbekannte Variable. **Das ist die Fassung für neun von zehn Fällen:** kein
  Bild, keine Route, funktioniert in jedem Client.
- `{{ countdown_image until="…" width="480" }}` rendert ein `<img>` auf die
  neue signierte Route `GET /!/statamic-email-templates/countdown.png` (Query
  `until`, `w`, `bg`, `fg`, `label`, `expired`; `throttle:60,1`;
  `Cache-Control: max-age=60`). GD zeichnet „dd : hh : mm" als Siebensegment-
  Anzeige, nach Ablauf `00 : 00 : 00` plus „vorbei". Ohne `ext-gd` oder mit
  `email-templates.countdown.image => false` antwortet die Route 404 und
  schreibt eine Warnung ins Log. Ohne gültige Signatur 403.

Die README sagt, was man sich mit dem Bild einkauft: Gmail holt es über seinen
Proxy bei jedem Öffnen neu (und jedes Öffnen ist eine Anfrage an den Server),
Apple Mail Privacy Protection holt es einmal vorab und zeigt danach dauerhaft
diesen Stand.

Dafür kamen `Support\Countdown`, `Support\FunctionTags` (der zweite, schmale
Durchlauf für Tags mit Parametern, nach den einfachen `{{ dotted.key }}`),
`Support\CountdownImage`, `Http\Controllers\CountdownImageController`,
`routes/actions.php`, die Sprachdatei `countdown.php` (de/en) und der
Config-Schlüssel `countdown.image`.

### Fixed — eingesetzte Werte landeten roh im HTML der Mail

`MergeVariables::apply()` setzte jeden gelieferten Wert unverändert ein. Ein
Name aus einem Formular mit `<script>` darin wurde damit zu Markup in einer Mail
— dieselbe Klasse Fehler, die am selben Tag in `statamic-payments` behoben
wurde. Die README nannte das ausdrücklich als Eigenschaft („inserted verbatim");
das war eine Zusage, die niemand einlösen konnte, weil das versendende Addon den
HTML-Kontext nicht kennt, in den sein Wert fällt.

Skalare werden jetzt beim Einsetzen mit `e()` escaped. Zwei Ausnahmen, beide
benannt statt stillschweigend:

- **`MergeVariables::RAW_VARIABLES`** — heute `unsubscribe_url`, eine Adresse
  dieses Pakets, die als `href` gebraucht wird. Hier stehen nur die Schlüssel,
  die **dieses** Paket liefert: ein Name hier ist für jeden Konsumenten roh,
  auch für die, die ihn nie escapt haben.
- **`apply($text, $data, raw: ['order.lines'])`** — für Schlüssel, die der
  Aufrufer selbst liefert und die schon Markup tragen. `statamic-funnels` baut
  `order.lines` aus `e()`-escapten Teilen mit `<br>` dazwischen, weil eine Liste
  von Bestellzeilen ohne Trenner-Markup nicht in eine HTML-Mail kommt. Der
  Aufrufer nennt seinen Schlüssel pro Aufruf, statt ihn für alle roh zu machen,
  und escaped wird weiterhin genau einmal je Wert. Positional übergeben, nicht
  benannt: ein Konsument, der noch gegen 2.2.x läuft, ignoriert zusätzliche
  Argumente stillschweigend, ein unbekanntes benanntes Argument wäre ein Fatal.
- **`apply($text, $data, escape: false)`** — für Ausgaben, die kein HTML sind:
  Betreffzeile und Plaintext-Teil. Dort wäre ein `&amp;` sichtbarer Schaden
  statt Schutz. Die Live-Vorschau ruft so für Betreff und Preheader auf: beide
  werden an ihrem Ausgabeort schon einmal escaped (`htmlspecialchars` im
  Vorschau-Dokument bzw. in `EmailPreheader::html()`), ein zweites Mal ergäbe
  `&amp;amp;`.

Die Reihenfolge in `apply()` bleibt: erst die einfachen `{{ dotted.key }}` mit
Escaping, danach `FunctionTags`. Das `<img>` von `{{ countdown_image }}`
entsteht also **nach** dem Escaping und escaped seine eigenen Attribute, bleibt
somit ein `<img>`.

**Für Aufrufer:** wer heute `apply()` für eine Betreffzeile benutzt, muss
`escape: false` nachtragen, sonst steht `&amp;` im Betreff.

## 2.2.0 — 2026-08-24

### Fixed — die Vorschau zeigte jeder Marke denselben Absender

`MergeVariables` löste `{{ sender.name }}` und `{{ sender.email }}` aus
`config('mail.from.*')` auf. Auf einem Host mit mehreren Marken hieß das: jede
Vorlage wurde mit demselben Absender vorgeschaut, und für alle Marken bis auf
eine war er falsch. **Gesendet wurde so nie etwas** — die Vorschau ist ein
eigener Weg — aber eine Vorschau, deren Absender gelogen ist, taugt nicht für
das eine, wofür man sie aufmacht.

Steht `statamic-brand-context` zur Verfügung, kommt der Absender jetzt von der
aktuellen Marke. **Die Kopplung ist optional** (`class_exists`, dazu ein
`suggest`-Eintrag): dieses Paket verlangt brand-context nicht, und eine
Installation ohne es verhält sich unverändert.


All notable changes to `statamic-email-templates` are documented here.

This file was reconstructed from the release tags on 2026-07-30; entries up to
1.2.1 are written from the tagged commits rather than recorded at the time.

## 2.1.2 — 2026-08-14

### Fixed — das Nachrüsten des Marken-Feldes überschrieb den ganzen Blueprint

Beim Upgrade auf 2.1.x wurde der Blueprint komplett neu geschrieben, statt nur
das fehlende Feld einzusetzen. Der Blueprint liegt aber in `resources/` der
Seite und darf dort bearbeitet worden sein — umsortierte Felder, geänderte
Hinweistexte, ein lesbar benanntes `layout`-Wahlfeld. All das kam als
Paket-Standard zurück.

Auf dem Hub genau so passiert: aus der Layout-Option
`FamilyStack (Paper-Craft)` wurde `Familystack`, der aus dem Handle erzeugte
Name. Nichts fiel aus, niemand bekam eine Meldung, und niemand schaut an dem Tag
in diese Datei — das ist die Sorte Upgrade, die schlimmer ist als eine, die gar
nichts tut.

Jetzt wird das Feld an das Ende des ersten Abschnitts **eingesetzt**, der Rest
der Datei bleibt, wie die Seite ihn hat. Zwei Tests halten das fest: ein
selbst hinzugefügtes Feld überlebt das Upgrade, und drei Bootvorgänge
hintereinander erzeugen das Marken-Feld genau einmal.

## 2.1.1 — 2026-08-14

### Fixed — 2.1.0 ging aus einer veralteten Kopie hervor und war noch MIT

Der Marken-Stand aus 2.1.0 wurde auf einem lokalen `main` gebaut, dem die beiden
Commits aus 2.0.0 fehlten — der Lizenzwechsel auf proprietär und dessen
CHANGELOG-Eintrag. Der Tag zeigt deshalb auf einen Stand, der `composer.json`
und die Lizenzdatei noch mit MIT führt.

Am Code des Addons ändert sich zwischen 2.1.0 und 2.1.1 nichts: identische
Klassen, identische Tests. Was dazukommt, ist der Merge mit 2.0.0.

`v2.1.0` bleibt, wo es ist. Eine veröffentlichte Version ist unveränderlich, und
Packagist hat das Umhängen des Tags korrekt abgelehnt — der Weg dafür ist eine
neue Version, nicht ein bewegter Tag.

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
