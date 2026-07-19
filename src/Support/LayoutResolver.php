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
 * ever throws for a bad value; the caller still guards `View::exists()` before
 * rendering.
 */
class LayoutResolver
{
    /**
     * Resolve a per-entry `layout` handle to a Blade view name, or null when no
     * usable layout is configured anywhere in the chain.
     */
    public static function resolve(?string $handle = null): ?string
    {
        $layouts = config('email-templates.layouts');
        $layouts = is_array($layouts) ? $layouts : [];

        // 1. The entry's own choice.
        if (($view = static::lookup($layouts, $handle)) !== null) {
            return $view;
        }

        // 2. The configured default layout handle.
        $default = config('email-templates.default_layout');
        if (($view = static::lookup($layouts, is_string($default) ? $default : null)) !== null) {
            return $view;
        }

        // 3. The single branded layout (historic behaviour).
        $branded = config('email-templates.branded_layout');

        return is_string($branded) && trim($branded) !== '' ? $branded : null;
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
