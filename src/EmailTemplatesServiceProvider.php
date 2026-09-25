<?php

namespace Goldnead\EmailTemplates;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\EmailTemplates\Actions\SendTestEmail;
use Goldnead\EmailTemplates\Console\AssignBrandCommand;
use Goldnead\EmailTemplates\Console\ImportEmailTemplatesCommand;
use Goldnead\EmailTemplates\CoreMails\CoreMails;
use Goldnead\EmailTemplates\Entries\EmailTemplateEntry;
use Goldnead\EmailTemplates\Listeners\ShowWhereTemplatesAreSent;
use Goldnead\EmailTemplates\Registry\TemplateRegistry;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Services\EmailTemplateResolver;
use Goldnead\EmailTemplates\Support\Brands;
use Goldnead\EmailTemplates\Support\MarketingEmailTemplateSource;
use Goldnead\EmailTemplates\Support\Settings;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Hooks\CP\EntriesIndexQuery;
use Statamic\Providers\AddonServiceProvider;

/**
 * Service provider for the Email Templates addon.
 *
 * Owns the shared, CP-native `et_templates` collection: it ensures the
 * collection + blueprint exist, registers the import command, the resolver
 * (exposed publicly via the EmailTemplates facade) and a CP nav entry that
 * points at the native collection listing.
 *
 * automations + marketing consume this addon *optionally*. There is no hard
 * dependency in either direction: the import command's MarketingEmailTemplateSource
 * is a soft dependency (returns [] when marketing is absent), and the resolver
 * takes a caller-supplied fallback so consumers stay decoupled.
 */
class EmailTemplatesServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        ImportEmailTemplatesCommand::class,
        AssignBrandCommand::class,
    ];

    /**
     * Core would find this by autoloading `src/Actions` anyway, so the entry is
     * belt-and-braces — but it is also the only place a reader of this file
     * learns that the addon puts anything in the Control Panel's action menu.
     */
    protected $actions = [
        SendTestEmail::class,
    ];

    /**
     * Front-end route that renders the native Live Preview iframe contents.
     * Statamic mounts it at the site root, just before the catch-all frontend
     * route, so the specific path always wins.
     */
    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
        // Under Statamic's action prefix (`/!/`): the PNG behind {{ countdown_image }}.
        'actions' => __DIR__.'/../routes/actions.php',
        // Under `/cp/`, behind the CP's authentication: the snapshot preview
        // that marketing, notifications and automations put in an iframe.
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/email-templates.php', 'email-templates');

        $this->allowEntryClassToBeUnserialized();

        // Singletons so registered import sources / bindings persist for the
        // request. The resolver is the facade accessor target.
        $this->app->singleton(EmailTemplateCollectionManager::class);
        $this->app->singleton(EmailTemplateResolver::class);

        // Import sources are container-tagged so additional file-based template
        // sources can be contributed without touching the import command. The
        // marketing source is a soft dependency (no-op when marketing absent).
        $this->app->bind(MarketingEmailTemplateSource::class);
        $this->app->tag([MarketingEmailTemplateSource::class], 'email-templates.sources');

        // Where addons announce which mail goes out when. The string alias
        // lets a sibling register without importing a class from here.
        //
        // The account mails of Statamic and Laravel are registered like any
        // addon's. Sending them from templates is still off until the
        // `core_mails.enabled` setting says otherwise.
        $this->app->singleton(TemplateRegistry::class, function () {
            $registry = new TemplateRegistry;
            CoreMails::register($registry);

            return $registry;
        });
        $this->app->alias(TemplateRegistry::class, TemplateRegistry::ALIAS);
        $this->app->singleton(CoreMails::class);
    }

    /**
     * Put EmailTemplateEntry on the cache's unserialize allowlist.
     *
     * The `et_templates` collection sets `entry_class`, so every entry in it is
     * an EmailTemplateEntry, and the Stache writes those objects into the cache
     * store. Laravel reads that cache back with
     * `unserialize($payload, ['allowed_classes' => config('cache.serializable_classes')])`.
     * A class that is missing from the list does not come back as itself, it
     * comes back as `__PHP_Incomplete_Class`, and the first method call on it
     * throws. Statamic registers its own classes the same way in
     * `Statamic\Providers\AppServiceProvider::register()`; an addon that ships
     * its own entry class has to add it.
     *
     * The list has to be complete before the cache store is instantiated, so
     * this belongs in `register()`, not in `bootAddon()`.
     *
     * `null` or `true` means the site runs without an allowlist. Nothing to add
     * then, and writing a list would switch the restriction on for a site that
     * did not ask for it. Same guard as core uses.
     */
    protected function allowEntryClassToBeUnserialized(): void
    {
        $existing = $this->app['config']->get('cache.serializable_classes');

        if ($existing === null || $existing === true) {
            return;
        }

        $this->app['config']->set('cache.serializable_classes', array_merge(
            is_array($existing) ? $existing : [],
            [EmailTemplateEntry::class],
        ));
    }

    /**
     * Announce the settings to the suite's shared screen.
     *
     * In `boot()`, not `bootAddon()`, and that is not a style choice.
     * brand-context applies the stored overrides from an `app->booted()`
     * callback so that every provider's `boot()` has had its turn first.
     * `bootAddon()` runs from an `app->booted()` callback of its own (Statamic's
     * AppServiceProvider), and which of the two fires first depends on package
     * load order — registering there would mean the settings reach the live
     * config on some installs and not on others, with nothing on screen saying
     * which.
     *
     * Guarded by `class_exists`, because brand-context is a suggest here and not
     * a requirement: `Support\Settings` implements one of its interfaces, so it
     * must not even be autoloaded on an install without the package.
     */
    public function boot(): void
    {
        parent::boot();

        if (! class_exists(SettingsRegistry::class)) {
            return;
        }

        $this->app->make(SettingsRegistry::class)->register(Settings::class);
    }

    public function bootAddon(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'email-templates');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-templates');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/email-templates.php' => config_path('email-templates.php'),
        ], 'email-templates-config');

        $this->ensureCollection();
        $this->computeWhereTemplatesAreSent();
        $this->scopeListingToCurrentBrand();
        $this->registerPermissions();
        $this->registerNavigation();
    }

    /**
     * The one permission this addon registers.
     *
     * It gates the addon's section on the shared settings screen — the templates
     * themselves are entries in a collection and are gated by Statamic's own
     * collection permissions, which need nothing from here.
     */
    protected function registerPermissions(): void
    {
        if (! class_exists(Permission::class)) {
            return;
        }

        Permission::register('manage email-templates settings')
            ->label(__('email-templates::email_templates.permission_settings'));
    }

    /**
     * The value behind the "Sent on" column: which addon sends a template, and
     * on which occasion, as registered in {@see TemplateRegistry}. Empty for a
     * template nobody claims, which is the site's own.
     *
     * A computed value, not stored data: it follows the installed code, so an
     * addon update that renames its occasion shows up without touching content.
     * The field it fills is added by {@see ShowWhereTemplatesAreSent}.
     */
    protected function computeWhereTemplatesAreSent(): void
    {
        Collection::computed(
            EmailTemplateCollectionManager::HANDLE,
            ShowWhereTemplatesAreSent::FIELD,
            function ($entry) {
                $slug = (string) $entry->slug();
                $sentOn = $this->app->make(TemplateRegistry::class)->describe($slug);

                // A host toMailUsing() that keeps the template from ever
                // being sent is said in the list, not only on the form.
                if ($sentOn !== null && $this->app->make(CoreMails::class)->blockedBy($slug) !== null) {
                    $sentOn .= ' · '.__('email-templates::email_templates.core_mail_blocked_short');
                }

                return $sentOn;
            },
        );
    }

    /**
     * Filter the Control Panel listing of `et_templates` to the current brand.
     *
     * Statamic's entry listing knows about sites, not brands, so without this
     * the screen showed every brand's templates to every brand — which is what
     * Adrian saw on the hub: `?brand=gldnr-studio` and a list of FamilyStack
     * mails. The brand switcher changed the header and nothing else.
     *
     * `EntriesIndexQuery` is core's own hook for exactly this, so the filter
     * sits in the query rather than in a filter the user has to remember to
     * apply — and rather than in a rewritten controller, which would have to be
     * rewritten again on every Statamic release.
     *
     * Only this collection. The hook fires for every collection in the install,
     * and a site's own pages have no brand field to filter on.
     */
    protected function scopeListingToCurrentBrand(): void
    {
        if (! class_exists(EntriesIndexQuery::class) || ! Brands::active()) {
            return;
        }

        EntriesIndexQuery::hook('query', function ($payload, $next) {
            if ($payload->collection?->handle() === EmailTemplateCollectionManager::HANDLE) {
                $brand = Brands::current();

                // No resolved brand means no answer, not every answer. The
                // Control Panel always has one (brand-context falls back to the
                // default), so this is the console and the odd unauthenticated
                // render — neither of which should leak one brand's mails into
                // another's screen.
                $payload->query->where(Brands::FIELD, $brand);
            }

            return $next($payload);
        });
    }

    /**
     * Ensure the shared `et_templates` collection + blueprint exist so the
     * native CP listing and publish form are available. Idempotent; guarded by
     * a feature flag (default on) and wrapped so a not-yet-ready Stache during
     * early console commands (e.g. package discovery) never breaks boot.
     *
     * The failure is non-fatal but never silent: this writes into the site's own
     * content directory, so a permissions problem, a corrupt YAML file or a
     * blueprint conflict has to reach the operator's log. Warning level, not
     * error: the next boot and the import command both retry.
     */
    protected function ensureCollection(): void
    {
        if (! config('email-templates.enabled', true)) {
            return;
        }

        try {
            $this->app->make(EmailTemplateCollectionManager::class)->ensure();
        } catch (\Throwable $e) {
            Log::warning(
                'email-templates: could not ensure the et_templates collection. '
                .'The CP listing and publish form may be missing until this is resolved.',
                ['exception' => $e]
            );
        }
    }

    protected function registerNavigation(): void
    {
        if (! config('email-templates.enabled', true)) {
            return;
        }

        Nav::extend(function ($nav) {
            $nav->content(__('email-templates::nav.email_templates'))
                ->section('Content')
                ->icon('mail')
                ->url(cp_route('collections.show', EmailTemplateCollectionManager::HANDLE));

            $this->hideCollectionFromNav($nav);
        });
    }

    /**
     * Drop `et_templates` from the automatic list under Content → Collections.
     *
     * Statamic lists every collection there, so the addon's own entry above put
     * the same screen in the sidebar twice, under two different names — and the
     * one nobody chose sat next to the site's real collections as if email
     * templates were pages.
     *
     * Removed by the collection's *stored* title rather than the translation
     * key: the title is whatever the site saved, a translation is what this
     * process happens to be speaking, and only the first is what core put in
     * the nav. Nothing happens when the collection is absent — the child is
     * simply not there to remove.
     */
    protected function hideCollectionFromNav($nav): void
    {
        $collection = Collection::findByHandle(EmailTemplateCollectionManager::HANDLE);

        if (! $collection) {
            return;
        }

        $nav->remove('Content', 'Collections', $collection->title());
    }
}
