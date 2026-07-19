<?php

namespace Goldnead\EmailTemplates\Support;

/**
 * Converts legacy HTML into the Bard value (a ProseMirror node list) so the
 * import command can store file-based templates in the Bard body field.
 *
 * This is the inverse of BardHtmlRenderer. It exists only for the import path:
 * templates authored directly in the CP are already Bard nodes and never pass
 * through here.
 *
 * Fidelity note: tiptap's default schema keeps structural markup (headings,
 * lists, links, images, tables) but drops inline styles / unknown attributes.
 * Simple transactional templates round-trip cleanly; heavily styled marketing
 * HTML may lose styling and is a documented follow-up (see README).
 */
class HtmlToBard
{
    /**
     * @return array<int,array<string,mixed>>  ProseMirror node list for a Bard field.
     */
    public function convert(string $html): array
    {
        $html = trim($html);

        if ($html === '') {
            return [];
        }

        try {
            if (class_exists(\Tiptap\Editor::class)) {
                $doc = (new \Tiptap\Editor([
                    'extensions' => [
                        new \Tiptap\Extensions\StarterKit,
                        new \Tiptap\Marks\Link,
                        new \Tiptap\Marks\Underline,
                    ],
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
