<?php

namespace Goldnead\EmailTemplates;

use Goldnead\EmailTemplates\Console\ImportEmailTemplatesCommand;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Services\EmailTemplateResolver;
use Goldnead\EmailTemplates\Support\MarketingEmailTemplateSource;
use Statamic\Facades\CP\Nav;
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
    ];

    /**
     * Front-end route that renders the native Live Preview iframe contents.
     * Statamic mounts it at the site root, just before the catch-all frontend
     * route, so the specific path always wins.
     */
    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/email-templates.php', 'email-templates');

        // Singletons so registered import sources / bindings persist for the
        // request. The resolver is the facade accessor target.
        $this->app->singleton(EmailTemplateCollectionManager::class);
        $this->app->singleton(EmailTemplateResolver::class);

        // Import sources are container-tagged so additional file-based template
        // sources can be contributed without touching the import command. The
        // marketing source is a soft dependency (no-op when marketing absent).
        $this->app->bind(MarketingEmailTemplateSource::class);
        $this->app->tag([MarketingEmailTemplateSource::class], 'email-templates.sources');
    }

    public function bootAddon(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'email-templates');

        $this->publishes([
            __DIR__.'/../config/email-templates.php' => config_path('email-templates.php'),
        ], 'email-templates-config');

        $this->ensureCollection();
        $this->registerNavigation();
    }

    /**
     * Ensure the shared `email_templates` collection + blueprint exist so the
     * native CP listing and publish form are available. Idempotent; guarded by
     * a feature flag (default on) and wrapped so a not-yet-ready Stache during
     * early console commands (e.g. package discovery) never breaks boot.
     */
    protected function ensureCollection(): void
    {
        if (! config('email-templates.enabled', true)) {
            return;
        }

        try {
            $this->app->make(EmailTemplateCollectionManager::class)->ensure();
        } catch (\Throwable $e) {
            // Non-fatal: the collection is (re)ensured on the next boot and by
            // the import command. Never let it take down the whole addon.
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
        });
    }
}
