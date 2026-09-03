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
            return $this->prepareUrls(trim($value));
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

                return $this->prepareUrls((string) (new Editor([
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
                return $this->prepareUrls((string) $augmentor::convertToHtml($value));
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return '';
    }

    /**
     * Make every `<img src>` and `<a href>` absolute.
     *
     * An address in an email body is resolved by a machine that knows nothing
     * about the site that sent it: the client fetching a picture, the reader's
     * browser opening a link. `/assets/flyer.png` and `/kurs` resolve against
     * the site in a browser and against nothing at all in an inbox, so a
     * Statamic asset or an internal link — exactly what an author picks in Bard
     * — arrives broken. Silently, both of them: no client reports the reason,
     * and the CP preview looks right, because a browser *does* have the site as
     * its base.
     *
     * Applied to every path out of `render()`, the raw-HTML one included: an
     * imported legacy template carries the same relative addresses. It runs
     * before statamic-automations rewrites links for LeadHub click tracking,
     * which is the right order — that rewriter needs a real URL to work with.
     *
     * @see rewriteAttribute() for what is deliberately left alone.
     */
    protected function prepareUrls(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        if (stripos($html, '<img') !== false) {
            $html = $this->rewriteAttribute($html, 'img', 'src');
        }

        if (stripos($html, '<a') !== false) {
            $html = $this->rewriteAttribute($html, 'a', 'href');
        }

        return $html;
    }

    /**
     * Rewrite one attribute of one tag to an absolute URL.
     *
     * Four kinds of value are left exactly as they are:
     *
     * - **Anything carrying a merge tag.** This runs *before* MergeVariables
     *   does, so `<a href="{{ unsubscribe_url }}">` still holds its tag here.
     *   `url()` would percent-encode the braces into `%7B%7B`, the substitution
     *   afterwards would no longer match, and the reader would get a link to a
     *   page named after the variable. 2.5.0 shipped exactly that bug for
     *   images. A `{{ }}` in an address is the normal case, not an exotic one —
     *   an unsubscribe link is one.
     * - **Anything with a scheme**, `https:` through `mailto:`, `tel:`, `data:`
     *   and `cid:`. Rewriting those would break embedded images, attachments
     *   and every mail-to link in a footer.
     * - **Protocol-relative** `//host/path`.
     * - **Fragments** (`#`). `href="#"` is a deliberate non-link, and pointing
     *   `#abschnitt` at a page that may not have that section is guesswork.
     */
    protected function rewriteAttribute(string $html, string $tag, string $attribute): string
    {
        return (string) preg_replace_callback(
            '/(<'.$tag.'\b[^>]*?\b'.$attribute.'\s*=\s*)(["\'])(.*?)\2/i',
            function (array $m): string {
                $value = trim($m[3]);

                $leaveAlone = $value === ''
                    || str_contains($value, '{{')
                    || str_starts_with($value, '#')
                    || str_starts_with($value, '//')
                    || preg_match('~^[a-z][a-z0-9+.-]*:~i', $value) === 1;

                return $leaveAlone ? $m[0] : $m[1].$m[2].url($value).$m[2];
            },
            $html
        );
    }
}
