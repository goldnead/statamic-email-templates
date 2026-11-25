<?php

namespace Goldnead\EmailTemplates\Support;

/**
 * Maps a template entry's chosen `layout` handle to the Blade view that should
 * wrap its body. Pure config lookup with a defined precedence, so both the send
 * path (EmailTemplateResolver) and the CP Live Preview resolve identically.
 *
 * Precedence (see {@see resolve()}):
 *   1. the entry's own `layout` handle → its view in `email-templates.layouts`
 *   2. the configured `default_layout` handle → its view in that same map
 *   3. `email-templates.branded_layout` — the ultimate, back-compatible fallback
 *
 * Any unknown/blank handle simply falls through to the next step, so nothing
 * ever throws for a bad value. This class only reads config: it does not ask
 * whether a view exists. {@see BrandedBodyRenderer::resolveLayout()} walks
 * {@see chain()} and takes the first view that does.
 */
class LayoutResolver
{
    /**
     * Resolve a per-entry `layout` handle to a Blade view name, or null when no
     * usable layout is configured anywhere in the chain.
     */
    public static function resolve(?string $handle = null): ?string
    {
        return static::chain($handle)[0] ?? null;
    }

    /**
     * Every configured view for $handle in precedence order (entry layout,
     * default layout, branded layout), without duplicates. Whether a view
     * exists is not asked here: the renderer walks this list and takes the
     * first one that does.
     *
     * @return list<string>
     */
    public static function chain(?string $handle = null): array
    {
        $layouts = config('email-templates.layouts');
        $layouts = is_array($layouts) ? $layouts : [];

        $default = config('email-templates.default_layout');
        $branded = config('email-templates.branded_layout');

        $views = [
            // 1. The entry's own choice.
            static::lookup($layouts, $handle),
            // 2. The configured default layout handle.
            static::lookup($layouts, is_string($default) ? $default : null),
            // 3. The single branded layout (historic behaviour).
            is_string($branded) && trim($branded) !== '' ? $branded : null,
        ];

        return array_values(array_unique(array_filter($views, fn ($view) => $view !== null)));
    }

    /**
     * Return the mapped view for $handle in $layouts, or null when the handle is
     * blank, unmapped, or maps to a blank value.
     *
     * @param  array<string,mixed>  $layouts
     */
    protected static function lookup(array $layouts, ?string $handle): ?string
    {
        if (! is_string($handle) || trim($handle) === '') {
            return null;
        }

        $view = $layouts[$handle] ?? null;

        return is_string($view) && trim($view) !== '' ? $view : null;
    }
}
