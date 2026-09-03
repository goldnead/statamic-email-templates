<?php

namespace Goldnead\EmailTemplates\Support;

/**
 * Everything this addon knows about brands, and the only place it touches
 * `goldnead/statamic-brand-context`.
 *
 * The dependency is soft in both directions: no composer requirement, no import
 * of a brand-context class outside this file, and every method answers
 * truthfully on an install where the package is absent — which is the default,
 * because a single-brand site is what most installs are. `active()` is false
 * there, and the rest of the addon behaves exactly as it did before brands
 * existed: one set of templates, no field on the form, no filter on the listing.
 *
 * **Why templates need brands at all.** A template is addressed by slug, and a
 * slug is what an automation, a campaign or a transactional send asks for. Two
 * brands that both send a `welcome` mail need two different `welcome` templates
 * and no way to reach each other's. Without this, the first `welcome` in the
 * collection answers for everybody — which is what Adrian saw on the hub: the
 * FamilyStack templates showing under gldnr.studio, because they were the only
 * ones there and nothing said which brand they belonged to.
 */
class Brands
{
    /** The blueprint handle and entry key the brand is stored under. */
    public const FIELD = 'brand';

    /**
     * Is this a multi-brand install with brand-context available?
     *
     * Both halves matter. The package can be installed and left in single-brand
     * mode, which is its own supported configuration: one brand, no isolation,
     * and a brand field on this form would be a control with one option.
     */
    public static function active(): bool
    {
        $manager = static::manager();

        if ($manager === null) {
            return false;
        }

        try {
            return (bool) $manager->multiBrandEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The handle of the brand the request is running as, or null when none is
     * resolved.
     *
     * Null is a real answer, not an error: a queue job, a console command or a
     * public route can run without a brand. Callers decide what that means —
     * the listing shows nothing rather than everything, and the resolver falls
     * back to the unbranded lookup.
     */
    public static function current(): ?string
    {
        $manager = static::manager();

        if ($manager === null) {
            return null;
        }

        try {
            if (! $manager->hasCurrent()) {
                return null;
            }

            $brand = $manager->current();

            return static::handleOf($brand);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The handle of the default brand, or null. */
    public static function default(): ?string
    {
        $manager = static::manager();

        if ($manager === null) {
            return null;
        }

        try {
            return static::handleOf($manager->default());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every brand, as select options for the blueprint field.
     *
     * @return array<string, string> handle => name
     */
    public static function options(): array
    {
        // The model, not the manager: brand-context's manager answers "which
        // brand is this request" and has no listing method. Reached by name so
        // the class is only touched where the package is installed.
        $model = 'Goldnead\\BrandContext\\Models\\Brand';

        if (static::manager() === null || ! class_exists($model)) {
            return [];
        }

        try {
            // Without this the list is scoped to the current brand and offers
            // exactly one option — the one already selected.
            $brands = $model::withoutGlobalScopes()->orderBy('name')->get();
            $options = [];

            foreach ($brands as $brand) {
                $handle = static::handleOf($brand);

                if ($handle === null) {
                    continue;
                }

                $options[$handle] = static::nameOf($brand) ?? $handle;
            }

            return $options;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Run a callback as a given brand, and hand back whatever it returns.
     *
     * Without this, anything that renders a template runs as whatever brand the
     * *request* resolved to, not the one the template belongs to. On a preview
     * that is the logged-in editor's brand: opening a Chorwerkstatt template as
     * a Nordlicht user rendered it with Nordlicht's sender name, and once the
     * host app's mail shell started reading the brand, in Nordlicht's colour
     * too. It looked like a working preview of the wrong mail, which is the
     * expensive kind of wrong. Found on the demo, 03.09.2026.
     *
     * Falls through to a plain call when the handle is null (an unbranded
     * template, or a single-brand install) or when brand-context is absent —
     * that is the default install, and it must behave exactly as before.
     */
    public static function runFor(?string $handle, \Closure $callback): mixed
    {
        $manager = static::manager();

        if ($handle === null || $handle === '' || $manager === null || ! method_exists($manager, 'runFor')) {
            return $callback();
        }

        try {
            return $manager->runFor($handle, $callback);
        } catch (\Throwable) {
            // An unknown or deleted handle is no reason to fail the render: the
            // caller still wants its mail, just without the brand swap.
            return $callback();
        }
    }

    /** The brand-context manager, or null when the package is not installed. */
    protected static function manager(): ?object
    {
        if (! app()->bound('brand-context')) {
            return null;
        }

        try {
            return app('brand-context');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read a handle off whatever `current()` / `default()` hands back.
     *
     * Those return a model in the versions this was written against, but the
     * contract is not this addon's to rely on — an id or a handle string are
     * both plausible, and guessing wrong would silently scope every template to
     * a brand that does not exist.
     */
    protected static function handleOf(mixed $brand): ?string
    {
        if (is_string($brand)) {
            return $brand !== '' ? $brand : null;
        }

        if (is_object($brand) && isset($brand->handle) && is_string($brand->handle)) {
            return $brand->handle !== '' ? $brand->handle : null;
        }

        return null;
    }

    protected static function nameOf(mixed $brand): ?string
    {
        if (is_object($brand) && isset($brand->name) && is_string($brand->name)) {
            return $brand->name !== '' ? $brand->name : null;
        }

        return null;
    }
}
