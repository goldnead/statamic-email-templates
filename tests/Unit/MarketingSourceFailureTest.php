<?php

use Goldnead\EmailTemplates\Support\MarketingEmailTemplateSource;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Fakes/MarketingRepositoryContract.php';

it('logs when the marketing repository fails, so "nothing to import" is not the only trace', function () {
    Log::spy();
    app()->bind(EmailTemplateRepository::class, fn () => new class implements EmailTemplateRepository
    {
        public function all(): iterable
        {
            throw new RuntimeException('marketing db down');
        }
    });

    expect((new MarketingEmailTemplateSource)->all())->toBe([]);

    Log::shouldHaveReceived('warning')->withArgs(
        fn ($message, $context = []) => str_contains((string) $message, 'statamic-marketing')
            && ($context['exception'] ?? null) === RuntimeException::class
    )->once();
});

it('stays quiet when marketing is simply not bound', function () {
    Log::spy();

    expect((new MarketingEmailTemplateSource)->all())->toBe([]);

    Log::shouldNotHaveReceived('warning');
});
