<?php

namespace Goldnead\EmailTemplates\Services;

use Goldnead\EmailTemplates\Support\EmailTemplateBlueprint;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Support\HtmlToBard;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

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

    public function __construct(
        protected HtmlToBard $htmlToBard,
    ) {
    }

    /**
     * Ensure the collection and its blueprint exist. Idempotent and cheap to
     * call on every boot — both writes are skipped once present.
     */
    public function ensure(): void
    {
        if (! Collection::findByHandle(self::HANDLE)) {
            Collection::make(self::HANDLE)
                ->title(__('email-templates::email_templates.collection_title'))
                ->routes(null)
                ->revisionsEnabled(false)
                ->save();
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
