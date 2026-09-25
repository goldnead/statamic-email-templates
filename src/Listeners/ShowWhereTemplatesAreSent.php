<?php

namespace Goldnead\EmailTemplates\Listeners;

use Goldnead\EmailTemplates\Registry\TemplateRegistry;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Statamic;

/**
 * Puts "Sent on" into the template listing and the edit form, and lists the
 * placeholders the sender fills.
 *
 * Added to the blueprint in memory on each Control Panel request, never saved
 * into the site's blueprint file: the value comes from the registry, which
 * belongs to the installed code, not to the content. Same mechanism core uses
 * for its own `site` column (`Statamic\Entries\AddSiteColumnToBlueprint`).
 *
 * The listing column is a computed value (`Collection::computed()`, registered
 * in the service provider). The edit form additionally gets a read-only
 * `html` field with the placeholder table — only when the form is for one
 * entry, because only then is it known which template it is.
 */
class ShowWhereTemplatesAreSent
{
    public const FIELD = 'sent_on';

    public const PLACEHOLDERS_FIELD = 'template_placeholders';

    public function __construct(protected TemplateRegistry $registry) {}

    public function handle(EntryBlueprintFound $event): void
    {
        if ($event->blueprint->namespace() !== 'collections.'.EmailTemplateCollectionManager::HANDLE) {
            return;
        }

        if (! Statamic::isCpRoute()) {
            return;
        }

        // A textarea, not a text input: read-only, a single-line input cut
        // "Statamic: Passwort vergessen auf der Website" off in the sidebar.
        $event->blueprint->ensureField(self::FIELD, [
            'type' => 'textarea',
            'display' => __('email-templates::email_templates.field_sent_on'),
            'instructions' => __('email-templates::email_templates.field_sent_on_instructions'),
            'visibility' => 'computed',
            'listable' => true,
            'localizable' => false,
        ], 'sidebar');

        $slug = $event->entry?->slug();

        if (! is_string($slug) || ($definition = $this->registry->find($slug)) === null || $definition->placeholders() === []) {
            return;
        }

        $event->blueprint->ensureField(self::PLACEHOLDERS_FIELD, [
            'type' => 'html',
            'display' => __('email-templates::email_templates.field_placeholders'),
            'instructions' => __('email-templates::email_templates.field_placeholders_instructions'),
            'html' => $this->placeholderTable($definition->placeholders()),
            'listable' => false,
            'localizable' => false,
        ], 'sidebar');
    }

    /**
     * @param  array<string, array{label: string, example: mixed}>  $placeholders
     */
    protected function placeholderTable(array $placeholders): string
    {
        // Stacked, not a two-column table: the sidebar is about 280px wide,
        // and a tag like `{{ expires_minutes }}` next to its label left the
        // label a word per line. Colours are inherited so dark mode follows.
        $items = '';

        foreach ($placeholders as $key => $spec) {
            $items .= '<li style="margin:0 0 10px">'
                .'<code style="font-size:12px">{{ '.e($key).' }}</code>'
                .'<div style="font-size:13px;opacity:.75;margin-top:2px">'.e($spec['label']).'</div>'
                .'</li>';
        }

        return '<ul style="list-style:none;margin:0;padding:0">'.$items.'</ul>';
    }
}
