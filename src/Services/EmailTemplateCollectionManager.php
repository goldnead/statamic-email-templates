<?php

namespace Goldnead\EmailTemplates\Services;

use Goldnead\EmailTemplates\Support\EmailTemplateBlueprint;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Support\HtmlToBard;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

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

    // Internal front-end route pattern. Its ONLY purpose is to satisfy Statamic's
    // `Entry::livePreviewUrl()` gate (returns null unless collection->route() is
    // set), so the native Live Preview button appears. Real front-end visits find
    // no template and 404; the split-screen renders via LIVE_PREVIEW_ROUTE.
    public const FRONTEND_ROUTE = '_email-template-preview/{slug}';

    public function __construct(
        protected HtmlToBard $htmlToBard,
    ) {
    }

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

        // Give the collection a route so Statamic's native Live Preview button
        // appears (Entry::livePreviewUrl() is gated on collection->route()).
        // This is an internal gate only — real front-end visits 404; the actual
        // split-screen renders via the preview target below.
        if (! $collection->route(Site::default()->handle())) {
            $collection->routes(self::FRONTEND_ROUTE);
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

        if ($needsSave) {
            $collection->save();
        }

        if (! Blueprint::find(EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE)) {
            EmailTemplateBlueprint::make()->save();
        }
    }

    /** Find a template entry by slug (or null). */
    public function findBySlug(string $slug): ?EntryContract
    {
        return Entry::query()
            ->where('collection', self::HANDLE)
            ->where('slug', $slug)
            ->first();
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
