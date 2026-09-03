<?php

namespace Goldnead\EmailTemplates\Support;

use Statamic\Fields\Value;
use Statamic\Fieldtypes\Bard\Augmentor;
use Tiptap\Editor;

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
            return $this->prepareImages(trim($value));
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

                return $this->prepareImages((string) (new Editor([
                    'extensions' => TiptapExtensions::all(),
                ]))->setContent($doc)->getHTML());
            }
        } catch (\Throwable $e) {
            // fall through to the Statamic augmentor
        }

        // Fallback: Statamic's own Bard augmentor prosemirror -> HTML converter.
        try {
            $augmentor = Augmentor::class;
            if (class_exists($augmentor) && method_exists($augmentor, 'convertToHtml')) {
                return $this->prepareImages((string) $augmentor::convertToHtml($value));
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return '';
    }

    /**
     * Make every `<img src>` absolute.
     *
     * A picture is the one piece of an email body that is not delivered with
     * it: the client fetches it later, from a machine that knows nothing about
     * the site that sent the mail. `/assets/flyer.png` resolves against the
     * site in a browser and against nothing at all in an inbox, so a Statamic
     * asset — which is exactly what an author picks in Bard — arrives as a
     * broken image. Silently: no client reports the reason, and the CP preview
     * looks right, because a browser *does* have the site as its base.
     *
     * Applied to every path out of `render()`, the raw-HTML one included: an
     * imported legacy template carries the same relative paths.
     *
     * Left alone: anything already absolute, protocol-relative, `data:` or
     * `cid:`. Rewriting those would break embedded and attached images.
     */
    protected function prepareImages(string $html): string
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2/i',
            function (array $m): string {
                $src = trim($m[3]);

                $alreadyUsable = $src === ''
                    || preg_match('~^[a-z][a-z0-9+.-]*:~i', $src) === 1
                    || str_starts_with($src, '//');

                return $alreadyUsable ? $m[0] : $m[1].$m[2].url($src).$m[2];
            },
            $html
        );
    }
}
