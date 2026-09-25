<?php

namespace Goldnead\EmailTemplates\Listeners;

use Goldnead\EmailTemplates\CoreMails\CoreMails;
use Goldnead\EmailTemplates\Registry\TemplateRegistry;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Fields\Blueprint;
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

    public function __construct(
        protected TemplateRegistry $registry,
        protected CoreMails $coreMails,
    ) {}

    public function handle(EntryBlueprintFound $event): void
    {
        if ($event->blueprint->namespace() !== 'collections.'.EmailTemplateCollectionManager::HANDLE) {
            return;
        }

        if (! Statamic::isCpRoute()) {
            return;
        }

        $slug = $event->entry?->slug();
        $route = (string) optional(request()->route())->getName();
        $isListing = in_array($route, ['statamic.cp.collections.show', 'statamic.cp.collections.entries.index'], true);

        // The blueprint object is one instance per process. The edit form
        // fires this event twice on it, once for the entry and once without
        // (core's own lookups), and a listing after a form in the same process
        // (Octane, tests) meets the form's fields. So the route decides what
        // the fields are, and a lookup without entry on a form route leaves
        // them alone.
        if (! $isListing && ! is_string($slug)) {
            return;
        }

        $this->withoutOwnFields($event->blueprint);

        // The listing: one column fed by the computed value.
        if ($isListing) {
            $event->blueprint->ensureField(self::FIELD, [
                'type' => 'text',
                'display' => __('email-templates::email_templates.field_sent_on'),
                'visibility' => 'computed',
                'listable' => true,
                'localizable' => false,
            ], 'sidebar');

            return;
        }

        // The edit form: plain text, not an input. Nothing here can be
        // edited, and a greyed-out input box says the opposite.
        $sentOn = $this->registry->describe($slug);
        $blockedBy = $this->coreMails->blockedBy($slug);

        $event->blueprint->ensureField(self::FIELD, [
            'type' => 'html',
            'display' => __('email-templates::email_templates.field_sent_on'),
            'html' => ($sentOn !== null
                ? '<p style="margin:0">'.e($sentOn).'</p>'
                : '<p style="margin:0;opacity:.75">'.e(__('email-templates::email_templates.field_sent_on_none')).'</p>')
                .($blockedBy !== null
                    ? '<p style="margin:8px 0 0;color:#b45309">'.e(__('email-templates::email_templates.core_mail_blocked', ['class' => $blockedBy])).'</p>'
                    : ''),
            'visibility' => 'computed',
            'listable' => false,
            'localizable' => false,
        ], 'sidebar');

        if (($definition = $this->registry->find($slug)) === null || $definition->placeholders() === []) {
            return;
        }

        // The generic help text names `{{ contact.first_name }}`, which a
        // registered sender never fills. Point at the template's own tags.
        // A name reads better as the example in a subject line than a link.
        $keys = array_keys($definition->placeholders());
        $example = '{{ '.(in_array('user.name', $keys, true) ? 'user.name' : $keys[0]).' }}';

        foreach (['subject', 'preview'] as $handle) {
            if ($event->blueprint->hasField($handle)) {
                $event->blueprint->ensureFieldHasConfig($handle, [
                    'instructions' => __("email-templates::email_templates.field_{$handle}_instructions_registered", ['example' => $example]),
                ]);
            }
        }

        $event->blueprint->ensureField(self::PLACEHOLDERS_FIELD, [
            'type' => 'html',
            'display' => __('email-templates::email_templates.field_placeholders'),
            'instructions' => __('email-templates::email_templates.field_placeholders_instructions'),
            'html' => $this->placeholderTable($definition->placeholders()),
            'visibility' => 'computed',
            'listable' => false,
            'localizable' => false,
        ], 'sidebar');
    }

    protected function withoutOwnFields(Blueprint $blueprint): void
    {
        // `ensureField()` keeps its fields in a list of its own and ignores a
        // second call for the same handle, so dropping them from that list is
        // what lets this request decide. Core has no public method for it.
        (function (array $handles) {
            foreach ($handles as $handle) {
                unset($this->ensuredFields[$handle]);
            }

            $this->resetBlueprintCache()->resetFieldsCache();
        })->call($blueprint, [self::FIELD, self::PLACEHOLDERS_FIELD]);
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
