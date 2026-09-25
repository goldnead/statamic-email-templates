<?php

namespace Goldnead\EmailTemplates\Facades;

use Goldnead\EmailTemplates\Registry\TemplateRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * Where addons announce their mails: slug, sending addon, occasion,
 * placeholders and the shipped default text.
 *
 * A sibling that must not depend on this package uses the container alias
 * instead: `app('email-templates.registry')->register([...])`.
 *
 * @method static \Goldnead\EmailTemplates\Registry\TemplateDefinition register(\Goldnead\EmailTemplates\Registry\TemplateDefinition|array $definition)
 * @method static bool has(string $slug)
 * @method static \Goldnead\EmailTemplates\Registry\TemplateDefinition|null find(string $slug)
 * @method static array all()
 * @method static array byAddon()
 * @method static string|null describe(string $slug)
 * @method static array examples(string $slug)
 *
 * @see TemplateRegistry
 */
class EmailTemplateRegistry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TemplateRegistry::class;
    }
}
