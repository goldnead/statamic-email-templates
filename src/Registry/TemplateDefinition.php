<?php

namespace Goldnead\EmailTemplates\Registry;

use Closure;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use InvalidArgumentException;

/**
 * What an addon tells this package about one of its mails: the slug it
 * resolves, which addon sends it, on which occasion, which placeholders the
 * sender fills in, and the text it ships as a default.
 *
 * Plain values only, so a sibling addon can register with an array and never
 * has to import a class from this package:
 *
 *     app('email-templates.registry')->register([
 *         'slug' => 'teams-invitation',
 *         'addon' => 'Teams',
 *         'trigger' => 'Jemand wird in ein Team eingeladen',
 *         'event' => \Goldnead\Teams\Events\InvitationSent::class,
 *         'placeholders' => [
 *             'team.name' => ['label' => 'Name des Teams', 'example' => 'Sopran 1'],
 *             'url' => 'Link zur Einladung',
 *         ],
 *         'defaults' => fn () => ['title' => …, 'subject' => …, 'body' => '<p>…</p>'],
 *     ]);
 *
 * Labels and defaults may be closures. They are called when read, so they are
 * translated in the locale of the request that shows them, not the one that
 * happened to boot the application.
 */
final class TemplateDefinition
{
    /**
     * @param  string|Closure():string  $title
     * @param  string|Closure():string  $addon
     * @param  string|Closure():string  $trigger
     * @param  array<string, string|Closure|array{label?: string|Closure, example?: mixed}>  $placeholders
     * @param  array<string, mixed>|Closure():array<string, mixed>|null  $defaults
     */
    public function __construct(
        public readonly string $slug,
        protected string|Closure $title = '',
        protected string|Closure $addon = '',
        protected string|Closure $trigger = '',
        public readonly ?string $event = null,
        protected array $placeholders = [],
        protected array|Closure|null $defaults = null,
    ) {
        if (trim($slug) === '') {
            throw new InvalidArgumentException('An email template definition needs a slug.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $placeholders = $data['placeholders'] ?? [];

        return new self(
            slug: (string) ($data['slug'] ?? ''),
            title: $data['title'] ?? '',
            addon: $data['addon'] ?? '',
            trigger: $data['trigger'] ?? '',
            event: isset($data['event']) && $data['event'] !== '' ? (string) $data['event'] : null,
            placeholders: is_array($placeholders) ? $placeholders : [],
            defaults: $data['defaults'] ?? null,
        );
    }

    public function title(): string
    {
        $title = self::text($this->title);

        return $title !== '' ? $title : ($this->defaults()['title'] ?? $this->slug);
    }

    /** Who sends it, e.g. "Statamic" or "Teams". */
    public function addon(): string
    {
        return self::text($this->addon);
    }

    /** On which occasion it goes out, in words an editor reads. */
    public function trigger(): string
    {
        return self::text($this->trigger);
    }

    /**
     * Every placeholder the sender fills, normalised.
     *
     * @return array<string, array{label: string, example: mixed}>
     */
    public function placeholders(): array
    {
        $out = [];

        foreach ($this->placeholders as $key => $spec) {
            $spec = is_array($spec) ? $spec : ['label' => $spec];

            $out[(string) $key] = [
                'label' => self::text($spec['label'] ?? ''),
                'example' => ($spec['example'] ?? null) instanceof Closure ? $spec['example']() : ($spec['example'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * The examples as a nested merge-data array, for Live Preview and the test
     * send: `user.name => 'Maria'` becomes `['user' => ['name' => 'Maria']]`.
     *
     * @return array<string, mixed>
     */
    public function examples(): array
    {
        $data = [];

        foreach ($this->placeholders() as $key => $spec) {
            if ($spec['example'] !== null) {
                data_set($data, $key, $spec['example']);
            }
        }

        return $data;
    }

    /**
     * The shipped text, as `email-templates:import` writes it.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = $this->defaults instanceof Closure ? ($this->defaults)() : $this->defaults;

        return is_array($defaults) ? $defaults : [];
    }

    public function hasDefaults(): bool
    {
        return $this->defaults() !== [];
    }

    public function toTemplateData(): EmailTemplateData
    {
        $defaults = $this->defaults();

        return EmailTemplateData::fromArray(array_merge([
            'title' => self::text($this->title) ?: $this->slug,
            'description' => $this->trigger(),
        ], $defaults, [
            'slug' => $this->slug,
            'source' => $this->addon() !== '' ? $this->addon() : 'registry',
        ]));
    }

    /**
     * @return array{slug: string, title: string, addon: string, trigger: string, event: string|null, placeholders: array<string, array{label: string, example: mixed}>}
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title(),
            'addon' => $this->addon(),
            'trigger' => $this->trigger(),
            'event' => $this->event,
            'placeholders' => $this->placeholders(),
        ];
    }

    protected static function text(mixed $value): string
    {
        if ($value instanceof Closure) {
            $value = $value();
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
