<?php

namespace Goldnead\EmailTemplates\Services;

use Goldnead\EmailTemplates\Entries\EmailTemplateEntry;
use Goldnead\EmailTemplates\Support\Brands;
use Goldnead\EmailTemplates\Support\EmailTemplateBlueprint;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Support\HtmlToBard;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint as BlueprintInstance;

/**
 * Owns the native Statamic `et_templates` collection: creating it (and its
 * blueprint) if absent, and reading/writing template entries by slug.
 *
 * The collection is a first-class Statamic collection, so it shows up in the
 * CP with the native listing + publish form, revisions and localization for
 * free. The slug is the stable cross-addon reference and is never rewritten.
 *
 * Writes convert the DTO's HTML body into the Bard ProseMirror value via
 * HtmlToBard, because the `body` field is Bard and stores nodes, not HTML.
 */
class EmailTemplateCollectionManager
{
    // Own, collision-free handle: a host app (e.g. adriangoldner.com) may already
    // run an unrelated `email_templates` collection with a different blueprint.
    // This addon owns `et_templates`. Single source of truth for the handle —
    // every other reference (blueprint namespace, nav route, resolver, preview,
    // import, marketing) derives from here.
    public const HANDLE = 'et_templates';

    // Front-end route (routes/web.php) that renders the Live Preview iframe
    // contents. Referenced as the collection's preview target so the native CP
    // Live Preview split-screen shows the real email HTML.
    public const LIVE_PREVIEW_ROUTE = 'email-templates/live-preview';

    // Legacy placeholder front-end route, written by v1.2.1 and earlier purely
    // to satisfy Statamic's `Entry::livePreviewUrl()` gate. EmailTemplateEntry
    // overrides that gate directly now, so the route serves no purpose and is
    // removed from collections that still carry it. Kept as a constant so the
    // cleanup can recognise it and never touches a route the user chose.
    public const LEGACY_FRONTEND_ROUTE = '_email-template-preview/{slug}';

    public function __construct(
        protected HtmlToBard $htmlToBard,
    ) {}

    /**
     * Ensure the collection, its blueprint and its Live Preview wiring exist.
     * Idempotent and cheap to call on every boot — the collection is only
     * (re)written when something is actually missing.
     */
    public function ensure(): void
    {
        $collection = Collection::findByHandle(self::HANDLE);
        $needsSave = false;

        if (! $collection) {
            $collection = Collection::make(self::HANDLE)
                ->title(__('email-templates::email_templates.collection_title'))
                ->revisionsEnabled(false);
            $needsSave = true;
        }

        // Entries are instantiated as EmailTemplateEntry, which enables the
        // native Live Preview button without the collection needing a front-end
        // route. Email templates are not public pages and must not occupy a URL.
        if ($collection->entryClass() !== EmailTemplateEntry::class) {
            $collection->entryClass(EmailTemplateEntry::class);
            $needsSave = true;
        }

        // Upgrade path: drop the placeholder route written by v1.2.1 and
        // earlier. Only ever removes the addon's own pattern, never a route a
        // site owner set themselves.
        if ($collection->routes()->contains(self::LEGACY_FRONTEND_ROUTE)) {
            $collection->routes(null);
            $needsSave = true;
        }

        // Point Live Preview at our custom render route (the rendered email),
        // not a front-end page. Guarded so boot stays cheap once applied.
        $target = '/'.self::LIVE_PREVIEW_ROUTE;

        $hasTarget = $collection->previewTargets()
            ->contains(fn ($t) => ($t['format'] ?? null) === $target);

        if (! $hasTarget) {
            $collection->previewTargets([
                [
                    'label' => __('email-templates::email_templates.live_preview_target'),
                    'format' => $target,
                    // Statamic's Collection reads this key unconditionally; omitting
                    // it throws "Undefined array key 'refresh'" when the entry loads.
                    'refresh' => true,
                ],
            ]);
            $needsSave = true;
        }

        // Email templates are not web pages — opt out of SEO Pro's injected
        // SEO tabs/fields (SEO Pro checks the collection cascade for `seo`).
        if ($collection->cascade('seo') !== false) {
            $collection->cascade(array_merge($collection->cascade()->all(), ['seo' => false]));
            $needsSave = true;
        }

        if ($needsSave) {
            $collection->save();
        }

        $blueprint = Blueprint::find(EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE);

        if (! $blueprint) {
            EmailTemplateBlueprint::make()->save();
        } elseif (Brands::active() && ! $blueprint->hasField(Brands::FIELD)) {
            $this->addBrandFieldTo($blueprint);
        }

        $this->backfillBrands();
    }

    /**
     * Add the brand field to a blueprint that was written before brands existed.
     *
     * **Inserted, not rewritten.** The blueprint is a file in the site's own
     * `resources/`, and a site owner may have edited it — reordered fields,
     * changed an instruction, renamed a `layout` option to something readable.
     * Re-saving `EmailTemplateBlueprint::make()` would silently replace all of
     * that with the packaged defaults, which is exactly what it did on the hub:
     * a hand-written layout label came back as the humanised handle. An upgrade
     * that quietly discards somebody's edits is worse than one that does
     * nothing, because nobody is looking at that file on the day it happens.
     *
     * The field goes at the end of the first section, which is where the last
     * of the packaged fields sits. Failing to find a section is not fatal — the
     * form simply keeps no brand control, and the entry class still stamps the
     * value, which is the same state as a site that never upgraded.
     */
    protected function addBrandFieldTo(BlueprintInstance $blueprint): void
    {
        $contents = $blueprint->contents();
        $field = EmailTemplateBlueprint::brandFieldDefinition();

        if ($field === []) {
            return;
        }

        $tabKey = array_key_first($contents['tabs'] ?? []);

        if ($tabKey === null) {
            return;
        }

        $sectionKey = array_key_first($contents['tabs'][$tabKey]['sections'] ?? []);

        if ($sectionKey === null) {
            return;
        }

        $contents['tabs'][$tabKey]['sections'][$sectionKey]['fields'][] = $field;

        $blueprint->setContents($contents)->save();
    }

    /**
     * File every template that predates the brand field under the default brand.
     *
     * Without this, switching an existing install to multi-brand empties the
     * listing: the entries carry no brand, the listing filters on one, and the
     * templates are still there and reachable by nothing. The default brand is
     * the same answer brand-context's own migration gave the tables it moved,
     * so a mixed install stays consistent with itself.
     *
     * A judgement call worth stating: this *guesses*. Templates written for one
     * particular brand land under the default one and have to be moved by hand.
     * The alternative — leaving them unbranded — makes them invisible, which is
     * the failure that is hard to diagnose rather than the one you can see and
     * fix on the form.
     *
     * Costs one query per boot and writes only on the boot after the switch.
     */
    protected function backfillBrands(): void
    {
        if (! Brands::active() || ($default = Brands::default()) === null) {
            return;
        }

        $orphans = Entry::query()
            ->where('collection', self::HANDLE)
            ->whereNull(Brands::FIELD)
            ->get();

        if ($orphans->isEmpty()) {
            return;
        }

        foreach ($orphans as $entry) {
            $entry->set(Brands::FIELD, $default);
            $entry->saveQuietly();
        }

        Log::info(sprintf(
            'email-templates: filed %d template(s) without a brand under [%s]. '
            .'Move any that belong to another brand on their edit form.',
            $orphans->count(),
            $default,
        ));
    }

    /**
     * Find a template entry by slug (or null).
     *
     * Under multi-brand the slug alone is not an address: two brands may each
     * have a `welcome`, and whichever the Stache happened to return first would
     * otherwise answer for both. Scoped to the brand the caller is running as —
     * and when there is none (a console command, a queue job outside a brand),
     * the lookup stays unscoped rather than returning nothing, because a send
     * that cannot name its brand is not the same thing as a send that belongs
     * to no brand.
     */
    public function findBySlug(string $slug): ?EntryContract
    {
        $query = Entry::query()
            ->where('collection', self::HANDLE)
            ->where('slug', $slug);

        if (Brands::active() && ($brand = Brands::current()) !== null) {
            $query->where(Brands::FIELD, $brand);
        }

        return $query->first();
    }

    /**
     * Create or update a template entry from normalized data, keyed by slug.
     * Returns [$entry, $created] where $created is false when an existing entry
     * was updated.
     *
     * @return array{0:EntryContract,1:bool}
     */
    public function upsert(EmailTemplateData $data): array
    {
        $entryData = $data->toEntryData();
        // Bard field stores ProseMirror nodes, so convert the HTML body here.
        $entryData['body'] = $this->htmlToBard->convert($data->body);

        $existing = $this->findBySlug($data->slug);

        if ($existing) {
            $existing->merge($entryData);
            $existing->save();

            return [$existing, false];
        }

        $entry = Entry::make()
            ->collection(self::HANDLE)
            ->slug($data->slug)
            ->data($entryData);

        $entry->save();

        return [$entry, true];
    }
}
