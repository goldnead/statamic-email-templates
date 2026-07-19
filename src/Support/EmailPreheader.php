<?php

namespace Goldnead\EmailTemplates\Support;

/**
 * Builds and injects the email *preheader* (a.k.a. preview text): the short
 * line inbox clients show next to the subject in the message list.
 *
 * The preheader is delivered as the industry-standard hidden snippet — a
 * visually-hidden `<div>` at the very top of the email body. It never renders
 * in the message itself (display:none, zero size, transparent), it only feeds
 * the client's inbox-preview. Because the snippet is prepended to the body
 * HTML, any merge-variable tokens it carries (`{{ contact.first_name }}`) are
 * resolved by exactly the same substitution pass the consuming addon runs over
 * the body at send time — so the preheader is treated identically to the
 * subject, never a second, divergent code path.
 */
class EmailPreheader
{
    /**
     * The hidden preheader snippet for $preview, or an empty string when the
     * preview text is blank (so nothing is injected for templates without one).
     */
    public static function html(string $preview): string
    {
        $preview = trim($preview);

        if ($preview === '') {
            return '';
        }

        // Escape for HTML safety; merge tokens like `{{ contact.first_name }}`
        // contain no special characters and pass through untouched, so a later
        // MergeVariables pass still resolves them.
        $text = htmlspecialchars($preview, ENT_QUOTES, 'UTF-8');

        return '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;'
            .'font-size:1px;line-height:1px;color:transparent;opacity:0;" aria-hidden="true">'
            .$text
            .'</div>';
    }

    /**
     * Prepend the hidden preheader snippet to $bodyHtml. Returns $bodyHtml
     * unchanged when the preview text is blank.
     */
    public static function prepend(string $bodyHtml, string $preview): string
    {
        $snippet = static::html($preview);

        return $snippet === '' ? $bodyHtml : $snippet.$bodyHtml;
    }
}
