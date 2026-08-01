<?php

namespace Goldnead\EmailTemplates\Support;

use Statamic\Fields\Value;
use Statamic\Fieldtypes\Bard\Augmentor;
use Tiptap\Editor;
use Tiptap\Extensions\StarterKit;
use Tiptap\Marks\Link;
use Tiptap\Marks\Underline;

/**
 * Renders a Bard body value into email-ready HTML.
 *
 * A Bard field stores its content as an array of ProseMirror nodes, which is
 * not HTML. This is the "Bard -> email HTML" render path: it turns that node
 * array into an HTML string suitable for an email body. The same path is used
 * by the resolver on every send, so what the CP preview shows and what the
 * recipient receives are produced identically.
 *
 * Rendering is defensive: a value that is already an HTML string is returned
 * untouched (covers save_html Bard configs and imported raw HTML), and any
 * failure degrades to an empty string rather than throwing mid-send.
 */
class BardHtmlRenderer
{
    /**
     * @param  mixed  $value  Bard prosemirror nodes (array), an HTML string, or a Statamic Value.
     */
    public function render(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof Value) {
            $value = $value->raw() ?? $value->value();
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value) || $value === []) {
            return '';
        }

        // Primary path: Statamic ships tiptap-php (the engine behind Bard).
        // Wrap the stored node list in a prosemirror `doc` and render to HTML.
        try {
            if (class_exists(Editor::class)) {
                $doc = (isset($value['type']) && $value['type'] === 'doc')
                    ? $value
                    : ['type' => 'doc', 'content' => $value];

                return (string) (new Editor([
                    'extensions' => [
                        new StarterKit,
                        new Link,
                        new Underline,
                    ],
                ]))->setContent($doc)->getHTML();
            }
        } catch (\Throwable $e) {
            // fall through to the Statamic augmentor
        }

        // Fallback: Statamic's own Bard augmentor prosemirror -> HTML converter.
        try {
            $augmentor = Augmentor::class;
            if (class_exists($augmentor) && method_exists($augmentor, 'convertToHtml')) {
                return (string) $augmentor::convertToHtml($value);
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return '';
    }
}
