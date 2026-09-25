<?php

namespace Goldnead\EmailTemplates\Registry;

use Goldnead\EmailTemplates\Contracts\EmailTemplateSource;

/**
 * The registered defaults of one addon, as an import source.
 *
 * One source per addon rather than one for the whole registry, so
 * `email-templates:import --source=Statamic` means what it says.
 */
class RegistryTemplateSource implements EmailTemplateSource
{
    /**
     * @param  list<TemplateDefinition>  $definitions
     */
    public function __construct(
        protected string $label,
        protected array $definitions,
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    public function all(): array
    {
        $templates = [];

        foreach ($this->definitions as $definition) {
            if ($definition->hasDefaults()) {
                $templates[] = $definition->toTemplateData();
            }
        }

        return $templates;
    }
}
