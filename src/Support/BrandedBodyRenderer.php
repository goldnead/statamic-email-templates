<?php

namespace Goldnead\EmailTemplates\Support;

use Illuminate\Support\Facades\Log;
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
 * Defensive: a configured layout view that does not exist is skipped (and
 * logged once per view and handle and process), the next one in the chain is
 * used. Only when no view in the chain exists is the body returned raw rather
 * than throwing mid-send.
 */
class BrandedBodyRenderer
{
    /** @var array<string, true> Views already reported missing in this process. */
    protected static array $reportedMissing = [];

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
        $chain = LayoutResolver::chain($layoutHandle);

        foreach ($chain as $view) {
            if (View::exists($view)) {
                return $view;
            }

            // Configured, but not there: a typo in the layouts map or a renamed
            // view. Without this the mail leaves without header, footer and
            // unsubscribe link, and nothing says why.
            $key = $view.'|'.$layoutHandle;

            if (! isset(static::$reportedMissing[$key])) {
                static::$reportedMissing[$key] = true;

                Log::warning('email-templates: configured layout view does not exist, trying the next one', [
                    'view' => $view,
                    'layout' => $layoutHandle,
                ]);
            }
        }

        return null;
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
