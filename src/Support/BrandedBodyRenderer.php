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

    /** Whether a usable branded layout is configured (set AND the view exists). */
    public static function enabled(): bool
    {
        $layout = static::layout();

        return $layout !== null && View::exists($layout);
    }

    /**
     * Wrap $bodyHtml in the configured branded layout. Returns a complete HTML
     * document when branding is active, and the body unchanged otherwise.
     */
    public static function wrap(string $bodyHtml, string $subject = ''): string
    {
        if (! static::enabled()) {
            return $bodyHtml;
        }

        return (string) View::make('email-templates::branded', [
            'layout' => static::layout(),
            'body' => $bodyHtml,
            'subject' => $subject,
        ])->render();
    }
}
