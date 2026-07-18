<?php

namespace Goldnead\EmailTemplates\Entries;

use Statamic\Entries\Entry;

/**
 * Custom entry class for the `et_templates` collection.
 *
 * The only reason it exists: enable Statamic's native Live Preview split-screen
 * for a collection that has no front-end route.
 *
 * The CP "Live Preview" button is gated on `Entry::livePreviewUrl()` being
 * non-null, and the base implementation returns null unless the collection has
 * a front-end route. Email templates are deliberately not public pages
 * (`routes(null)`), so the base check would hide the button. We override it to
 * point at the CP preview endpoint directly. The actual split-screen contents
 * are still rendered by the collection's custom preview target — our
 * `email-templates/live-preview` route — not by any front-end page, so no
 * template is ever exposed on the site.
 */
class EmailTemplateEntry extends Entry
{
    public function livePreviewUrl()
    {
        if (! $this->id()) {
            return null;
        }

        return cp_route('collections.entries.preview.edit', [
            $this->collectionHandle(),
            $this->id(),
        ]);
    }
}
