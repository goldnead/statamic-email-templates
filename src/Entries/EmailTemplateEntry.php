<?php

namespace Goldnead\EmailTemplates\Entries;

use Goldnead\EmailTemplates\Support\Brands;
use Statamic\Entries\Entry;

/**
 * The entry class for the `et_templates` collection.
 *
 * Core gates the native Live Preview button on `Entry::livePreviewUrl()`, which
 * returns null unless the collection has a front-end route. Email templates are
 * not web pages and must not occupy a public URL, so instead of giving the
 * collection a placeholder route that only ever 404s, the button is enabled here
 * directly. The split-screen itself renders through the collection's preview
 * target (`EmailTemplateCollectionManager::LIVE_PREVIEW_ROUTE`).
 */
class EmailTemplateEntry extends Entry
{
    public function livePreviewUrl()
    {
        return $this->cpUrl('collections.entries.preview.edit');
    }

    /**
     * Stamp the brand on the way in, when nothing has set one.
     *
     * Here rather than as a blueprint default, because a blueprint default only
     * reaches the Control Panel form. Templates also arrive from the import
     * command and from `EmailTemplateCollectionManager::upsert()`, and a
     * template written by either of those with no brand would be a template no
     * brand can find — invisible in every listing and unresolvable by every
     * send, with nothing anywhere saying why.
     *
     * The current brand, or the default when the write happens outside one (a
     * console import, a queue job). Never guessed from the content.
     */
    public function save()
    {
        if (Brands::active() && ! $this->get(Brands::FIELD)) {
            $brand = Brands::current() ?? Brands::default();

            if ($brand !== null) {
                $this->set(Brands::FIELD, $brand);
            }
        }

        return parent::save();
    }
}
