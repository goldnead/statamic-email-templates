<?php

namespace Goldnead\EmailTemplates\Registry;

use Goldnead\EmailTemplates\Contracts\EmailTemplateSource;
use Throwable;

/**
 * Which mail goes out when, for every addon that says so.
 *
 * An addon registers a {@see TemplateDefinition} per mail. From then on the
 * Control Panel shows "Sent on" next to the template, the edit form lists the
 * placeholders the sender fills, Live Preview and the test send use the
 * registered examples, and `email-templates:import` writes the shipped default.
 *
 * Reachable without importing a class, so a sibling can stay optionally
 * coupled: `app('email-templates.registry')` behind `app()->bound(...)`.
 *
 * Addons that only tag an {@see EmailTemplateSource} (the older, import-only
 * way) are still recognised: {@see describe()} finds their slug among the
 * tagged sources and at least names the addon.
 */
class TemplateRegistry
{
    public const ALIAS = 'email-templates.registry';

    /** @var array<string, TemplateDefinition> */
    protected array $definitions = [];

    /** @var array<string, string>|null slug => source label, built on first use */
    protected ?array $sourceLabels = null;

    /**
     * @param  TemplateDefinition|array<string, mixed>  $definition
     */
    public function register(TemplateDefinition|array $definition): TemplateDefinition
    {
        $definition = is_array($definition) ? TemplateDefinition::fromArray($definition) : $definition;

        // Last one wins: a host app may re-register a slug to reword the
        // trigger for its own editors.
        $this->definitions[$definition->slug] = $definition;

        return $definition;
    }

    public function has(string $slug): bool
    {
        return isset($this->definitions[$slug]);
    }

    public function find(string $slug): ?TemplateDefinition
    {
        return $this->definitions[$slug] ?? null;
    }

    /**
     * @return array<string, TemplateDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Definitions grouped by the addon that sends them.
     *
     * @return array<string, list<TemplateDefinition>>
     */
    public function byAddon(): array
    {
        $groups = [];

        foreach ($this->definitions as $definition) {
            $groups[$definition->addon() ?: 'Other'][] = $definition;
        }

        return $groups;
    }

    /**
     * The line the Control Panel shows for a template: "<addon>: <occasion>",
     * the addon alone for an import-only source, or null when nobody claims it.
     */
    public function describe(string $slug): ?string
    {
        if ($definition = $this->find($slug)) {
            $trigger = $definition->trigger();
            $addon = $definition->addon();

            return match (true) {
                // Addon first: the listing truncates long cells, and "who
                // sends it" is the part that must survive.
                $trigger !== '' && $addon !== '' => "{$addon}: {$trigger}",
                $trigger !== '' => $trigger,
                $addon !== '' => $addon,
                default => null,
            };
        }

        return $this->sourceLabels()[$slug] ?? null;
    }

    /**
     * Merge data for previewing a template: the registered examples.
     *
     * @return array<string, mixed>
     */
    public function examples(string $slug): array
    {
        return $this->find($slug)?->examples() ?? [];
    }

    /**
     * Slugs offered by tagged import sources, labelled with the source.
     *
     * @return array<string, string>
     */
    protected function sourceLabels(): array
    {
        if ($this->sourceLabels !== null) {
            return $this->sourceLabels;
        }

        $labels = [];

        try {
            foreach (app()->tagged('email-templates.sources') as $source) {
                if (! $source instanceof EmailTemplateSource || $source instanceof RegistryTemplateSource) {
                    continue;
                }

                foreach ($source->all() as $template) {
                    $labels[$template->slug] ??= $source->label();
                }
            }
        } catch (Throwable) {
            // A broken sibling source must not take the listing down. The
            // column then reads empty for its templates, nothing more.
        }

        return $this->sourceLabels = $labels;
    }
}
