<?php

namespace Goldnead\EmailTemplates\Support;

use Goldnead\EmailTemplates\Contracts\EmailTemplateSource;

/**
 * Imports the file-based email templates owned by goldnead/statamic-marketing.
 *
 * Marketing stores reusable email layouts as `EmailTemplate` records (handle,
 * name, html) behind its `EmailTemplateRepository` — flat YAML under
 * content/marketing/templates/ or the `marketing_templates` table. We map
 * handle -> slug (kept identical for backwards compatibility), name -> title,
 * html -> body.
 *
 * This is a SOFT dependency: everything is resolved through string class names
 * and the container, so this addon never hard-requires marketing. When
 * marketing is absent, all() returns [].
 */
class MarketingEmailTemplateSource implements EmailTemplateSource
{
    private const REPOSITORY = 'Goldnead\\Marketing\\Contracts\\Repositories\\EmailTemplateRepository';

    public function label(): string
    {
        return 'statamic-marketing';
    }

    public function all(): array
    {
        if (! interface_exists(self::REPOSITORY) || ! app()->bound(self::REPOSITORY)) {
            return [];
        }

        try {
            $repository = app(self::REPOSITORY);
            $templates = $repository->all();
        } catch (\Throwable $e) {
            return [];
        }

        return collect($templates)
            ->map(function ($template): ?EmailTemplateData {
                // Marketing's EmailTemplate exposes public handle/name/html.
                $handle = $this->readProperty($template, 'handle');
                $name = $this->readProperty($template, 'name');
                $html = $this->readProperty($template, 'html');

                if ($handle === null || $handle === '') {
                    return null;
                }

                return new EmailTemplateData(
                    slug: (string) $handle,
                    title: (string) ($name ?? $handle),
                    subject: '',
                    body: (string) ($html ?? ''),
                    source: 'statamic-marketing',
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    private function readProperty(mixed $object, string $property): mixed
    {
        if (is_array($object)) {
            return $object[$property] ?? null;
        }

        if (is_object($object) && isset($object->{$property})) {
            return $object->{$property};
        }

        return null;
    }
}
