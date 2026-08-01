<?php

namespace Goldnead\EmailTemplates\Entries;

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
}
