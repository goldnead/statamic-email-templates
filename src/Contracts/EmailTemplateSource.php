<?php

namespace Goldnead\EmailTemplates\Contracts;

use Goldnead\EmailTemplates\Support\EmailTemplateData;

/**
 * A source of pre-existing, file-based email templates that the import command
 * pulls into the `email_templates` collection. Implementations are soft
 * dependencies: each returns [] when its backing addon is not installed, so
 * the import command works whether or not marketing/automations are present.
 */
interface EmailTemplateSource
{
    /**
     * Human-readable label, used in command output.
     */
    public function label(): string;

    /**
     * All templates this source can offer, normalized. Returns [] when the
     * backing addon/storage is unavailable.
     *
     * @return array<int,EmailTemplateData>
     */
    public function all(): array;
}
