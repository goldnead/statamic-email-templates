<?php

use Goldnead\EmailTemplates\Entries\EmailTemplateEntry;
use Goldnead\EmailTemplates\EmailTemplatesServiceProvider;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateBlueprint;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

// -- Failure reporting ----------------------------------------------------
//
// `ensure()` writes into the site's own content directory on boot. When that
// write fails — permissions, corrupt YAML, a blueprint conflict — the addon
// must not take the site down, but it must also not disappear without a trace.
// Before v1.3.0 the throwable was swallowed by an empty catch block and the
// operator had nothing to go on.

it('logs a failing ensure() instead of swallowing it', function () {
    $manager = Mockery::mock(EmailTemplateCollectionManager::class);
    $manager->shouldReceive('ensure')->once()->andThrow(new RuntimeException('disk on fire'));
    $this->app->instance(EmailTemplateCollectionManager::class, $manager);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return str_contains($message, 'et_templates')
                && ($context['exception'] ?? null) instanceof RuntimeException;
        });

    Nav::shouldReceive('extend');

    $this->app->getProvider(EmailTemplatesServiceProvider::class)->bootAddon();
});

it('does not let a failing ensure() break boot', function () {
    $manager = Mockery::mock(EmailTemplateCollectionManager::class);
    $manager->shouldReceive('ensure')->andThrow(new RuntimeException('disk on fire'));
    $this->app->instance(EmailTemplateCollectionManager::class, $manager);

    Log::shouldReceive('warning');

    Nav::shouldReceive('extend');

    $this->app->getProvider(EmailTemplatesServiceProvider::class)->bootAddon();
})->throwsNoExceptions();

// -- Blueprint and collection ownership -----------------------------------
//
// The addon owns the *existence* of the collection and blueprint, not their
// contents. `ensure()` is a create-if-missing routine: once the site owner has
// edited the blueprint in the CP, or renamed the collection, boot must leave
// those edits alone. This is the contract the README states.

it('does not overwrite a blueprint the site owner has edited', function () {
    $handle = EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE;

    $contents = EmailTemplateBlueprint::make()->contents();
    $contents['tabs']['main']['sections'][0]['fields'][] = [
        'handle' => 'custom_field',
        'field' => ['type' => 'text', 'display' => 'Custom'],
    ];

    Blueprint::make(EmailTemplateBlueprint::HANDLE)
        ->setNamespace(EmailTemplateBlueprint::NAMESPACE)
        ->setContents($contents)
        ->save();

    app(EmailTemplateCollectionManager::class)->ensure();

    expect(Blueprint::find($handle)->hasField('custom_field'))->toBeTrue()
        ->and(Blueprint::find($handle)->hasField('subject'))->toBeTrue();
});

it('does not overwrite a collection title the site owner has changed', function () {
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)
        ->title('Meine E-Mail-Vorlagen')
        ->save();

    app(EmailTemplateCollectionManager::class)->ensure();

    expect(Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)->title())
        ->toBe('Meine E-Mail-Vorlagen');
});

it('is idempotent: repeated boots do not accumulate preview targets', function () {
    $manager = app(EmailTemplateCollectionManager::class);

    $manager->ensure();
    $manager->ensure();

    expect(Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)->previewTargets())
        ->toHaveCount(1);
});

it('restores the collection when it has been deleted', function () {
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)->delete();

    app(EmailTemplateCollectionManager::class)->ensure();

    expect(Collection::findByHandle(EmailTemplateCollectionManager::HANDLE))->not->toBeNull();
});

// -- Live Preview wiring, without a public URL ----------------------------

it('enables live preview through the entry class, not a front-end route', function () {
    $collection = Collection::findByHandle(EmailTemplateCollectionManager::HANDLE);

    expect($collection->entryClass())->toBe(EmailTemplateEntry::class)
        ->and($collection->routes()->filter()->all())->toBe([]);
});

it('removes the placeholder front-end route left behind by older versions', function () {
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)
        ->routes(EmailTemplateCollectionManager::LEGACY_FRONTEND_ROUTE)
        ->save();

    app(EmailTemplateCollectionManager::class)->ensure();

    expect(Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)->routes()->filter()->all())
        ->toBe([]);
});

it('leaves a route the site owner set themselves alone', function () {
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)
        ->routes('/vorlagen/{slug}')
        ->save();

    app(EmailTemplateCollectionManager::class)->ensure();

    expect(Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)->routes()->filter()->values()->all())
        ->toBe(['/vorlagen/{slug}']);
});

// -- Master switch ---------------------------------------------------------

it('ensures nothing when the addon is disabled', function () {
    config()->set('email-templates.enabled', false);

    $manager = Mockery::mock(EmailTemplateCollectionManager::class);
    $manager->shouldNotReceive('ensure');
    $this->app->instance(EmailTemplateCollectionManager::class, $manager);

    Nav::shouldReceive('extend');

    $this->app->getProvider(EmailTemplatesServiceProvider::class)->bootAddon();
});

// -- Multi-site ------------------------------------------------------------

it('marks the content fields localizable and the layout choice global', function () {
    $blueprint = Blueprint::find(EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE);

    // Wording differs per site; which shell wraps a template does not.
    foreach (['title', 'subject', 'preview', 'body', 'plain_text'] as $handle) {
        expect($blueprint->field($handle)->isLocalizable())->toBeTrue("field [$handle] must be localizable");
    }

    expect($blueprint->field('layout')->isLocalizable())->toBeFalse();
});
