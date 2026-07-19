<?php

namespace Goldnead\EmailTemplates\Support;

use Illuminate\Support\Facades\View;

/**
 * Wraps an email body-HTML fragment in the host app's *branded* email shell
 * (header / footer / styling) so nothing leaves the addon ungebranded.
 *
 * The addon ships marketplace-generic by default: `email-templates.branded_layout`
 * is `null`, and {@see wrap()} returns the raw body untouched (the historic
 * behaviour). A host app opts in by pointing that config key at one of its own
 * Blade layouts — e.g. adriangoldner.com sets it to `emails.layout`, the same
 * shell its native branded mails already use.
 *
 * The wrapping is done through a tiny addon view, {@see resources/views/branded.blade.php},
 * which `@extends` the configured layout and injects the body into its
 * `@yield('content')` section. So the exact same header/footer the site renders
 * for a native mail now surrounds every managed-template body — in the CP Live
 * Preview and on every real send alike, since both flow through this one class.
 *
 * Defensive: if the configured layout view does not exist, {@see enabled()} is
 * false and the body is returned raw rather than throwing mid-send.
 */
class BrandedBodyRenderer
{
    /** The configured branded layout view name, or null when unset/blank. */
    public static function layout(): ?string
    {
        $layout = config('email-templates.branded_layout');

        return is_string($layout) && trim($layout) !== '' ? $layout : null;
    }

    /**
     * The Blade view that wraps an entry with the given `layout` handle, per
     * {@see LayoutResolver} (entry handle → default_layout → branded_layout).
     * Null when no usable layout resolves anywhere in that chain.
     */
    public static function resolveLayout(?string $layoutHandle = null): ?string
    {
        return LayoutResolver::resolve($layoutHandle);
    }

    /**
     * Whether a usable layout resolves for the given `layout` handle (it maps to
     * a view AND that view exists). With no handle this is the historic check
     * against `branded_layout`.
     */
    public static function enabledFor(?string $layoutHandle = null): bool
    {
        $layout = static::resolveLayout($layoutHandle);

        return $layout !== null && View::exists($layout);
    }

    /** Back-compat alias: is the single `branded_layout` fallback usable? */
    public static function enabled(): bool
    {
        return static::enabledFor(null);
    }

    /**
     * Wrap $bodyHtml in the layout chosen for this entry ($layoutHandle → view
     * via {@see LayoutResolver}). Returns a complete HTML document when a usable
     * layout resolves, and the body unchanged otherwise (unknown/missing layout).
     */
    public static function wrap(string $bodyHtml, string $subject = '', ?string $layoutHandle = null): string
    {
        $layout = static::resolveLayout($layoutHandle);

        if ($layout === null || ! View::exists($layout)) {
            return $bodyHtml;
        }

        return (string) View::make('email-templates::branded', [
            'layout' => $layout,
            'body' => $bodyHtml,
            'subject' => $subject,
        ])->render();
    }
}
