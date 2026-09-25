<?php

namespace Goldnead\EmailTemplates\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * The settings an operator may change from the Control Panel, and the one place
 * that knows what those are.
 *
 * Only the field list lives here. The screen, the form, the validation, the
 * store, the brand dimension and the permission check all come from
 * `goldnead/statamic-brand-context` — this class implements its
 * {@see ProvidesSettings} contract and nothing else. There is no controller, no
 * route, no Vue page and no `email_templates_settings` table in this package,
 * and there must not be.
 *
 * **Overrides, not a copy.** Only keys somebody actually changed are stored.
 * Everything else keeps following `config/email-templates.php`, so a site that
 * never opens the screen behaves exactly like one running a release from before
 * it existed.
 *
 * ## What is deliberately not offered, and why
 *
 * - **`enabled`**, the addon's master switch. It is read in `bootAddon()` —
 *   once by `ensureCollection()` and once by `registerNavigation()`
 *   (`EmailTemplatesServiceProvider:178,195`). The shared settings layer applies
 *   stored overrides from an `app->booted()` callback, so both readers have
 *   already seen the packaged value by the time an override could arrive. A
 *   switch on this screen would look effective and take effect on the next
 *   deploy, which is a lie in the interface. It stays in the config file.
 * - **`layouts`**, the `handle => Blade view` map. A nested map, not a value:
 *   editing it means adding, renaming and removing rows, and a removed handle
 *   strands every template entry that chose it. The two keys that *select* from
 *   it are here.
 * - **`preview.sample_data`**, the placeholder recipient. Also a nested map, and
 *   one whose shape follows whatever variables the templates on this site use.
 *
 * All three keep working from `config/email-templates.php`; the group
 * descriptions on the screen say so, rather than leaving an operator to wonder
 * why a documented key has no control.
 */
class Settings implements ProvidesSettings
{
    /**
     * Stable forever: it is written into `brand_settings.namespace` on every
     * row, so renaming it orphans every override a site has made.
     */
    public static function settingsNamespace(): string
    {
        return 'email-templates';
    }

    /** The config root unset values keep following. */
    public static function settingsConfigPath(): string
    {
        return 'email-templates';
    }

    /**
     * The suite's naming rule: `manage <package name without the statamic-
     * prefix> settings`.
     *
     * New, not inherited — this addon shipped no permissions at all until now,
     * so there is nothing here that could be renamed out from under a user
     * group.
     */
    public static function settingsPermission(): string
    {
        return 'manage email-templates settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('email-templates::settings.groups.layout.title'),
                'description' => __('email-templates::settings.groups.layout.description'),
                'fields' => [
                    static::field('branded_layout', 'string', ['nullable' => true]),
                    static::field('default_layout', 'string', ['nullable' => true]),
                ],
            ],
            [
                'title' => __('email-templates::settings.groups.snapshots.title'),
                'description' => __('email-templates::settings.groups.snapshots.description'),
                'fields' => [
                    static::field('snapshots.enabled', 'boolean'),
                ],
            ],
            [
                'title' => __('email-templates::settings.groups.test_send.title'),
                'description' => __('email-templates::settings.groups.test_send.description'),
                'fields' => [
                    // Nullable: an empty prefix is a real answer, and the one the
                    // config file itself recommends for a template whose subject
                    // needs all forty visible characters.
                    static::field('test_send.subject_prefix', 'string', ['nullable' => true]),
                ],
            ],
            [
                'title' => __('email-templates::settings.groups.core_mails.title'),
                'description' => __('email-templates::settings.groups.core_mails.description'),
                'fields' => [
                    // Read per send in SendCoreMailsFromTemplates, never at
                    // boot, so a change here takes effect on the next mail.
                    static::field('core_mails.enabled', 'boolean'),
                ],
            ],
            [
                'title' => __('email-templates::settings.groups.countdown.title'),
                'description' => __('email-templates::settings.groups.countdown.description'),
                'fields' => [
                    static::field('countdown.image', 'boolean'),
                ],
            ],
        ];
    }

    /**
     * One field, with label and description from the lang files.
     *
     * The translation key is the config path with the dots flattened, because a
     * dot in a lang key is a path separator to the translator and
     * `fields.test_send.subject_prefix.label` would be looked up as four nested
     * arrays that do not exist.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("email-templates::settings.fields.{$handle}.label"),
            'description' => __("email-templates::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
