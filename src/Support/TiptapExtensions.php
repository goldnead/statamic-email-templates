<?php

namespace Goldnead\EmailTemplates\Support;

use Tiptap\Extensions\StarterKit;
use Tiptap\Marks\Link;
use Tiptap\Marks\Underline;
use Tiptap\Nodes\Image;

/**
 * The one tiptap extension list, shared by both directions of the conversion.
 *
 * {@see HtmlToBard} parses HTML into ProseMirror nodes and {@see BardHtmlRenderer}
 * renders them back. Until 2.5.0 each built its own `new StarterKit, new Link,
 * new Underline` list, and the two agreed only by copy-paste. They stopped
 * agreeing with reality in the same way: `StarterKit` contains no `image` node,
 * so an `<img>` was dropped on the way in *and* rendered as nothing on the way
 * out — while the docblock of HtmlToBard listed images among what it keeps. A
 * picture in a template arrived as an empty mail, with no warning anywhere.
 *
 * Two directions that must produce the same schema belong in one list. A node
 * added here is added to both, which is the only arrangement in which they
 * cannot drift apart again.
 *
 * Still NOT included, deliberately: `Table`. That same docblock claimed tables
 * too and was wrong about them as well, but a table is not one node — it needs
 * Table, TableRow, TableHeader and TableCell together, and an email table wants
 * the `cellpadding`, `cellspacing` and `role="presentation"` that tiptap does
 * not emit. Left as an open question on
 * `backlog-email-templates-bilder-im-body` rather than half-built here.
 */
class TiptapExtensions
{
    /**
     * Default HTML attributes put on every rendered `<img>`.
     *
     * All of it is for mail clients, not browsers. `max-width:100%` keeps a
     * 1200px header graphic from forcing a sideways scroll in a phone client
     * that ignores the viewport, `height:auto` keeps it from being squashed
     * while it does so, and `border:0` suppresses the frame Outlook draws
     * around a linked image.
     *
     * `border` rides in the style string rather than as the `border="0"`
     * attribute Outlook guidance usually asks for, because tiptap-php cannot
     * emit it: `HTML::renderAttributes()` runs the attribute array through
     * `array_filter()` with no callback, and `'0'` is falsy in PHP, so any
     * attribute whose value is zero is dropped on the floor. Verified against
     * ueberdosis/tiptap-php on 2026-09-03. CSS is what remains, and it covers
     * every client that honours inline styles.
     *
     * @var array<string,string>
     */
    public const IMAGE_ATTRIBUTES = [
        'style' => 'max-width:100%;height:auto;border:0;',
    ];

    /**
     * @return list<object>
     */
    public static function all(): array
    {
        return [
            new StarterKit,
            new Link,
            new Underline,
            new Image(['HTMLAttributes' => self::IMAGE_ATTRIBUTES]),
        ];
    }
}
