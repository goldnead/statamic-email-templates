<?php

namespace Goldnead\Marketing\Contracts\Repositories;

/**
 * Stand-in for the contract of goldnead/statamic-marketing, which is not a
 * dev dependency here. MarketingEmailTemplateSource only checks that it
 * exists and is bound.
 */
interface EmailTemplateRepository
{
    public function all(): iterable;
}
