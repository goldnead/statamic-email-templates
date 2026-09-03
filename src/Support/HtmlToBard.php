<?php

namespace Goldnead\EmailTemplates\Support;

use Tiptap\Editor;

/**
 * Converts legacy HTML into the Bard value (a ProseMirror node list) so the
 * import command can store file-based templates in the Bard body field.
 *
 * This is the inverse of BardHtmlRenderer. It exists only for the import path:
 * templates authored directly in the CP are already Bard nodes and never pass
 * through here.
 *
 * Fidelity note: the schema in {@see TiptapExtensions} keeps headings, lists,
 * links and images, but drops inline styles and unknown attributes. Simple
 * transactional templates round-trip cleanly; heavily styled marketing HTML may
 * lose styling and is a documented follow-up (see README).
 *
 * **Tables are not kept.** Until 2.5.0 this note claimed they were, and claimed
 * the same of images — which is why the missing `image` node went unnoticed for
 * so long: reading the code could not reveal it, only sending a mail with a
 * picture in it could. Images are kept now. Tables really are still dropped.
 */
class HtmlToBard
{
    /**
     * @return array<int,array<string,mixed>> ProseMirror node list for a Bard field.
     */
    public function convert(string $html): array
    {
        $html = trim($html);

        if ($html === '') {
            return [];
        }

        try {
            if (class_exists(Editor::class)) {
                $doc = (new Editor([
                    'extensions' => TiptapExtensions::all(),
                ]))->setContent($html)->getDocument();

                if (is_array($doc) && isset($doc['content']) && is_array($doc['content'])) {
                    return $doc['content'];
                }
            }
        } catch (\Throwable $e) {
            // fall through to the plain-text fallback
        }

        // Fallback: a single paragraph carrying the stripped text. Guarantees a
        // valid Bard value even if tiptap is unavailable or the HTML is invalid.
        $text = trim(html_entity_decode(strip_tags($html)));

        if ($text === '') {
            return [];
        }

        return [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => $text,
            ]],
        ]];
    }
}
