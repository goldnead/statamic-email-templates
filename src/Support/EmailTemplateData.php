<?php

namespace Goldnead\EmailTemplates\Support;

use Illuminate\Support\Str;

/**
 * Normalized, driver-agnostic shape of an email template as it flows through
 * the import pipeline and out of the resolver. Sibling addons (automations,
 * marketing) and host apps only ever see this shape — never the underlying
 * Statamic entry, its Bard prosemirror value, or a foreign addon's DTO.
 *
 * `body` is always email-ready HTML: on the way in the import converts legacy
 * HTML into the Bard value; on the way out the resolver renders the Bard value
 * back to HTML. Consumers never deal with prosemirror.
 */
class EmailTemplateData
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $subject = '',
        public string $preview = '',
        public string $body = '',
        public ?string $plainText = null,
        public ?string $description = null,
        public string $source = 'entry',
    ) {
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $slug = (string) ($data['slug'] ?? $data['handle'] ?? '');
        $title = (string) ($data['title'] ?? $data['name'] ?? $slug);

        return new self(
            slug: $slug !== '' ? $slug : Str::slug($title),
            title: $title !== '' ? $title : $slug,
            subject: (string) ($data['subject'] ?? ''),
            preview: (string) ($data['preview'] ?? ''),
            body: (string) ($data['body'] ?? $data['html'] ?? ''),
            plainText: isset($data['plain_text']) ? (string) $data['plain_text'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            source: (string) ($data['source'] ?? 'entry'),
        );
    }

    /**
     * Scalar entry-data payload. NOTE: `body` is intentionally omitted here —
     * the CollectionManager writes the Bard-converted body separately, because
     * the Bard field stores prosemirror nodes, not the raw HTML this DTO holds.
     *
     * @return array<string,mixed>
     */
    public function toEntryData(): array
    {
        return array_filter([
            'title' => $this->title,
            'subject' => $this->subject,
            'preview' => $this->preview,
            'plain_text' => $this->plainText,
            'description' => $this->description,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'subject' => $this->subject,
            'preview' => $this->preview,
            'body' => $this->body,
            'plain_text' => $this->plainText,
            'description' => $this->description,
            'source' => $this->source,
        ];
    }
}
