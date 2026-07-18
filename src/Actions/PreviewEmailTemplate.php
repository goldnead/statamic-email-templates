<?php

namespace Goldnead\EmailTemplates\Actions;

use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * "Vorschau" — a native Statamic entry action that opens the email preview
 * page for the selected template. It appears in the email_templates listing
 * row menu and in the entry publish form's actions dropdown, so authors reach
 * the preview from wherever they are editing.
 *
 * The action itself does no work: it redirects to the preview page, which
 * renders the template through the real send path (resolver + BardHtmlRenderer)
 * with sample merge data.
 */
class PreviewEmailTemplate extends Action
{
    protected $confirm = false;

    public static function title()
    {
        return __('email-templates::preview.action_title');
    }

    public function visibleTo($item)
    {
        return $item instanceof EntryContract
            && optional($item->collection())->handle() === EmailTemplateCollectionManager::HANDLE;
    }

    /** Single-item redirect only — a bulk preview has no meaning. */
    public function visibleToBulk($items)
    {
        return false;
    }

    public function icon(): string
    {
        return 'mail';
    }

    public function redirect($items, $values)
    {
        $entry = $items->first();

        if (! $entry instanceof EntryContract) {
            return false;
        }

        return cp_route('email-templates.preview', ['id' => $entry->id()]);
    }
}
