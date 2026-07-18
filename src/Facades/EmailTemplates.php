<?php

namespace Goldnead\EmailTemplates\Facades;

use Goldnead\EmailTemplates\Services\EmailTemplateResolver;
use Illuminate\Support\Facades\Facade;

/**
 * Public entry point for sibling addons (automations, marketing) and host apps.
 *
 * @method static \Goldnead\EmailTemplates\Support\EmailTemplateData|null resolve(string $slug, ?callable $fallback = null)
 *
 * @see \Goldnead\EmailTemplates\Services\EmailTemplateResolver
 */
class EmailTemplates extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EmailTemplateResolver::class;
    }
}
