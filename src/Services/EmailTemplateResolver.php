<?php

namespace Goldnead\EmailTemplates\Services;

use Goldnead\EmailTemplates\Support\BardHtmlRenderer;
use Goldnead\EmailTemplates\Support\BrandedBodyRenderer;
use Goldnead\EmailTemplates\Support\EmailPreheader;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * Resolves an email template by slug. A managed `email_templates` entry always
 * wins; the file-based template is only used as a fallback, so migrating a
 * template into the CP overrides the file without anything breaking, and a
 * not-yet-migrated slug keeps resolving to its file template.
 *
 * The fallback is a caller-supplied callable so this stays decoupled from the
 * consuming addon (automations passes its inline body; marketing passes its
 * template repository lookup). That keeps this addon free of a hard dependency
 * on either sibling.
 *
 * The entry body is a Bard value; it is rendered to email HTML through the same
 * BardHtmlRenderer the CP preview uses, so what is resolved here is exactly
 * what a recipient receives.
 */
class EmailTemplateResolver
{
    public function __construct(
        protected EmailTemplateCollectionManager $collection,
        protected BardHtmlRenderer $renderer,
    ) {
    }

    /**
     * @param  (callable(string):(EmailTemplateData|array<string,mixed>|null))|null  $fallback
     */
    public function resolve(string $slug, ?callable $fallback = null): ?EmailTemplateData
    {
        $data = $this->lookup($slug, $fallback);

        if ($data === null) {
            return null;
        }

        // Inject the hidden preheader (preview text) at the very top of the
        // body. Its merge-variable tokens ride inside the body from here on, so
        // the consuming addon's body substitution pass resolves them exactly
        // like the subject — no separate preheader-only code path.
        $data->body = EmailPreheader::prepend($data->body, $data->preview);

        // Wrap the resolved body in the layout this entry chose (its `layout`
        // handle → view via LayoutResolver, falling back to `default_layout`
        // and finally the single `branded_layout`). No-op (returns the raw
        // body) when nothing usable resolves, so this stays a pure opt-in.
        // Applied here so every consumer of the resolver — the automations send
        // action above all — sends the branded HTML, matching the CP Live
        // Preview exactly.
        $data->body = BrandedBodyRenderer::wrap($data->body, $data->subject, $data->layout);

        return $data;
    }

    /**
     * Resolve the raw (un-branded) template data: managed entry wins, else the
     * caller-supplied fallback.
     *
     * @param  (callable(string):(EmailTemplateData|array<string,mixed>|null))|null  $fallback
     */
    protected function lookup(string $slug, ?callable $fallback): ?EmailTemplateData
    {
        $entry = $this->collection->findBySlug($slug);

        if ($entry) {
            return $this->fromEntry($slug, $entry);
        }

        if ($fallback === null) {
            return null;
        }

        $result = $fallback($slug);

        if ($result instanceof EmailTemplateData) {
            return $result;
        }

        if (is_array($result)) {
            $data = EmailTemplateData::fromArray($result + ['slug' => $slug]);
            $data->source = $data->source === 'entry' ? 'fallback' : $data->source;

            return $data;
        }

        return null;
    }

    protected function fromEntry(string $slug, EntryContract $entry): EmailTemplateData
    {
        return new EmailTemplateData(
            slug: $slug,
            title: (string) ($entry->value('title') ?? $slug),
            subject: (string) ($entry->value('subject') ?? ''),
            preview: (string) ($entry->value('preview') ?? ''),
            body: $this->renderer->render($entry->value('body')),
            plainText: $entry->value('plain_text') !== null ? (string) $entry->value('plain_text') : null,
            description: $entry->value('description') !== null ? (string) $entry->value('description') : null,
            layout: $entry->value('layout') !== null && (string) $entry->value('layout') !== '' ? (string) $entry->value('layout') : null,
            source: 'entry',
        );
    }
}
