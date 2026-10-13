<?php

use Goldnead\EmailTemplates\Support\EmailPreheader;

/**
 * The hidden preheader snippet must not trip spam filters.
 *
 * SpamAssassin's FONT_INVIS_MSGID (2.5 points) fires on a short inline style
 * that combines a tiny font size, a tiny line height and a transparent colour.
 * The snippet hides itself with display:none and zero size already, so those
 * three properties added nothing for clients and cost the whole mail its
 * reputation (measured with mail-tester, 13.10.2026).
 */
it('hides the preheader without the font tricks spam filters flag', function () {
    $html = EmailPreheader::html('Willkommen bei uns');

    expect($html)
        ->toContain('Willkommen bei uns')
        ->toContain('display:none')
        ->toContain('max-height:0')
        ->toContain('overflow:hidden')
        ->toContain('mso-hide:all')
        ->toContain('opacity:0')
        ->toContain('aria-hidden="true"')
        ->not->toContain('font-size')
        ->not->toContain('line-height')
        ->not->toContain('transparent');
});

it('injects nothing for a blank preview', function () {
    expect(EmailPreheader::html('  '))->toBe('')
        ->and(EmailPreheader::prepend('<p>x</p>', ''))->toBe('<p>x</p>');
});
