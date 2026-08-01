<?php

use Goldnead\EmailTemplates\Contracts\EmailTemplateSource;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/** An in-memory template source for exercising the import command. */
function fakeTemplateSource(array $templates, string $label = 'fake'): EmailTemplateSource
{
    return new class($templates, $label) implements EmailTemplateSource
    {
        public function __construct(private array $templates, private string $label) {}

        public function label(): string
        {
            return $this->label;
        }

        public function all(): array
        {
            return array_map(fn (array $t) => EmailTemplateData::fromArray($t), $this->templates);
        }
    };
}

/** Register a source under the container tag the import command reads. */
function registerFakeSource(EmailTemplateSource $source, string $key = 'email-templates.test.fake_source'): void
{
    app()->instance($key, $source);
    app()->tag([$key], 'email-templates.sources');
}
