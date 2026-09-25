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

        $event->blueprint->ensureField(self::FIELD, [
            'type' => 'text',
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
        $rows = '';

        foreach ($placeholders as $key => $spec) {
            $rows .= '<tr>'
                .'<td style="padding:4px 12px 4px 0;white-space:nowrap;vertical-align:top"><code>{{ '.e($key).' }}</code></td>'
                .'<td style="padding:4px 0;vertical-align:top">'.e($spec['label']).'</td>'
                .'</tr>';
        }

        return '<p style="margin:0 0 8px">'.e(__('email-templates::email_templates.field_placeholders_instructions')).'</p>'
            .'<table style="font-size:13px;border-collapse:collapse">'.$rows.'</table>';
    }
}
