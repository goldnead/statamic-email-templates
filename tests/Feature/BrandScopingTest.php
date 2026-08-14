<?php

use Goldnead\EmailTemplates\Entries\EmailTemplateEntry;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\Brands;
use Goldnead\EmailTemplates\Support\EmailTemplateBlueprint;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;

/**
 * Brand scoping, tested against a stand-in for `goldnead/statamic-brand-context`.
 *
 * The stand-in is the point, not a shortcut. This addon must not require the
 * brand package — most installs are single-brand — so it reaches it through the
 * container binding and nothing else. Binding a fake proves exactly that: if a
 * class name from the real package ever creeps into this addon, these tests keep
 * passing while the addon breaks on every install without it. Testing against
 * the real package would hide that.
 */
function fakeBrandContext(?string $current, string $default = 'default', bool $multi = true): void
{
    app()->instance('brand-context', new class($current, $default, $multi)
    {
        public function __construct(
            private ?string $current,
            private string $default,
            private bool $multi,
        ) {}

        public function multiBrandEnabled(): bool
        {
            return $this->multi;
        }

        public function hasCurrent(): bool
        {
            return $this->current !== null;
        }

        public function current(): object
        {
            return (object) ['handle' => $this->current, 'name' => $this->current];
        }

        public function default(): object
        {
            return (object) ['handle' => $this->default, 'name' => $this->default];
        }
    });
}

/** A template entry, written straight past the manager. */
function template(string $slug, ?string $brand = null): void
{
    $entry = Entry::make()
        ->collection(EmailTemplateCollectionManager::HANDLE)
        ->slug($slug)
        ->data(array_filter([
            'title' => $slug,
            'subject' => $slug,
            Brands::FIELD => $brand,
        ]));

    $entry->saveQuietly();
}

// -- Single-brand installs are untouched ----------------------------------
//
// The whole feature has to be invisible where the brand package is absent, and
// equally invisible where it is present but the install runs one brand. Both are
// supported configurations, and both had a working email-templates addon before
// brands existed.

it('adds no brand field when the brand package is absent', function () {
    expect(Brands::active())->toBeFalse();

    $fields = EmailTemplateBlueprint::make()->fields()->all();

    expect($fields->has(Brands::FIELD))->toBeFalse();
});

it('adds no brand field when the install runs a single brand', function () {
    fakeBrandContext(current: 'default', multi: false);

    expect(Brands::active())->toBeFalse();
    expect(EmailTemplateBlueprint::make()->fields()->all()->has(Brands::FIELD))->toBeFalse();
});

it('does not stamp a brand on a single-brand install', function () {
    $entry = Entry::make()
        ->collection(EmailTemplateCollectionManager::HANDLE)
        ->slug('welcome')
        ->data(['title' => 'Welcome']);

    $entry->save();

    expect($entry->get(Brands::FIELD))->toBeNull();
});

// -- Multi-brand ----------------------------------------------------------

it('adds a required brand field on a multi-brand install', function () {
    fakeBrandContext(current: 'gldnr-studio');

    $fields = EmailTemplateBlueprint::make()->fields()->all();

    expect($fields->has(Brands::FIELD))->toBeTrue();
    expect($fields->get(Brands::FIELD)->isRequired())->toBeTrue();
});

it('stamps the current brand on a template that arrives without one', function () {
    fakeBrandContext(current: 'gldnr-studio');

    $entry = Entry::make()
        ->collection(EmailTemplateCollectionManager::HANDLE)
        ->slug('welcome')
        ->data(['title' => 'Welcome']);

    $entry->save();

    expect($entry->get(Brands::FIELD))->toBe('gldnr-studio');
});

it('stamps the default brand when the write happens outside a brand', function () {
    // A console import or a queue job: brand-context is on, nothing resolved a
    // brand for this process. Filing under the default beats filing under
    // nothing, which would be a template no brand can ever find.
    fakeBrandContext(current: null, default: 'default');

    $entry = Entry::make()
        ->collection(EmailTemplateCollectionManager::HANDLE)
        ->slug('welcome')
        ->data(['title' => 'Welcome']);

    $entry->save();

    expect($entry->get(Brands::FIELD))->toBe('default');
});

it('never overwrites a brand that is already set', function () {
    fakeBrandContext(current: 'gldnr-studio');

    $entry = Entry::make()
        ->collection(EmailTemplateCollectionManager::HANDLE)
        ->slug('welcome')
        ->data(['title' => 'Welcome', Brands::FIELD => 'familystack']);

    $entry->save();

    expect($entry->get(Brands::FIELD))->toBe('familystack');
});

// -- Resolution by slug ---------------------------------------------------
//
// This is the defect Adrian reported, in its load-bearing form: the slug is
// what every send asks for, so two brands with the same slug must not be able
// to answer for each other.

it('resolves a slug within the current brand only', function () {
    fakeBrandContext(current: 'gldnr-studio');

    template('welcome', 'familystack');
    template('welcome', 'gldnr-studio');

    $manager = app(EmailTemplateCollectionManager::class);

    expect($manager->findBySlug('welcome')?->get(Brands::FIELD))->toBe('gldnr-studio');
});

it('does not resolve another brand\'s template', function () {
    fakeBrandContext(current: 'gldnr-studio');

    template('welcome', 'familystack');

    expect(app(EmailTemplateCollectionManager::class)->findBySlug('welcome'))->toBeNull();
});

it('resolves unscoped when no brand is current', function () {
    // Not a leak: a caller that cannot name its brand is not the same as a
    // caller that belongs to none, and refusing to resolve here would break
    // every console send on a multi-brand install.
    fakeBrandContext(current: null);

    template('welcome', 'familystack');

    expect(app(EmailTemplateCollectionManager::class)->findBySlug('welcome'))->not->toBeNull();
});

// -- Upgrade path ---------------------------------------------------------

it('files templates without a brand under the default brand', function () {
    template('legacy-one');
    template('legacy-two');

    fakeBrandContext(current: 'gldnr-studio', default: 'default');

    app(EmailTemplateCollectionManager::class)->ensure();

    $brands = Entry::query()
        ->where('collection', EmailTemplateCollectionManager::HANDLE)
        ->get()
        ->map(fn ($entry) => $entry->get(Brands::FIELD))
        ->all();

    expect($brands)->each->toBe('default');
});

it('leaves a template that already names a brand where it is', function () {
    template('theirs', 'familystack');

    fakeBrandContext(current: 'gldnr-studio', default: 'default');

    app(EmailTemplateCollectionManager::class)->ensure();

    expect(
        Entry::query()
            ->where('collection', EmailTemplateCollectionManager::HANDLE)
            ->where('slug', 'theirs')
            ->first()
            ?->get(Brands::FIELD)
    )->toBe('familystack');
});

it('keeps a site\'s own edits to the blueprint when it adds the field', function () {
    // The failure this replaces, found on the hub: the upgrade re-saved the
    // packaged blueprint, and a layout option somebody had renamed to
    // "FamilyStack (Paper-Craft)" came back as the humanised handle. An upgrade
    // that quietly discards an edit is worse than one that does nothing —
    // nobody is looking at that file on the day it happens.
    $blueprint = EmailTemplateBlueprint::make();
    $contents = $blueprint->contents();
    $contents['tabs']['main']['sections'][0]['fields'][] = [
        'handle' => 'house_note',
        'field' => ['type' => 'text', 'display' => 'A field this site added'],
    ];
    $blueprint->setContents($contents)->save();

    fakeBrandContext(current: 'gldnr-studio');

    app(EmailTemplateCollectionManager::class)->ensure();

    $fields = Blueprint::find(EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE)
        ->fields()->all();

    expect($fields->has(Brands::FIELD))->toBeTrue()
        ->and($fields->has('house_note'))->toBeTrue()
        ->and($fields->get('house_note')->display())->toBe('A field this site added');
});

it('adds the brand field only once, however often it boots', function () {
    EmailTemplateBlueprint::make()->save();

    fakeBrandContext(current: 'gldnr-studio');

    $manager = app(EmailTemplateCollectionManager::class);
    $manager->ensure();
    $manager->ensure();
    $manager->ensure();

    $contents = Blueprint::find(EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE)->contents();
    $handles = collect($contents['tabs']['main']['sections'][0]['fields'])->pluck('handle');

    expect($handles->filter(fn ($handle) => $handle === Brands::FIELD))->toHaveCount(1);
});

it('adds the brand field to a blueprint written before brands existed', function () {
    // The blueprint is written once and then left alone, so without this an
    // install that switched to multi-brand kept a form with no brand on it —
    // and every template saved through that form was stamped with a brand its
    // editor could neither see nor change.
    EmailTemplateBlueprint::make()->save();

    expect(
        Blueprint::find(EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE)
            ->hasField(Brands::FIELD)
    )->toBeFalse();

    fakeBrandContext(current: 'gldnr-studio');

    app(EmailTemplateCollectionManager::class)->ensure();

    expect(
        Blueprint::find(EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE)
            ->hasField(Brands::FIELD)
    )->toBeTrue();
});

// -- Failure modes --------------------------------------------------------

it('stays inactive when the brand manager throws', function () {
    app()->instance('brand-context', new class
    {
        public function multiBrandEnabled(): bool
        {
            throw new RuntimeException('brand table missing');
        }
    });

    // A half-migrated install must degrade to single-brand behaviour, not to a
    // Control Panel that 500s on every page.
    expect(Brands::active())->toBeFalse();
    expect(Brands::current())->toBeNull();
});

it('keeps the entry class saving when the brand cannot be resolved', function () {
    app()->instance('brand-context', new class
    {
        public function multiBrandEnabled(): bool
        {
            return true;
        }

        public function hasCurrent(): bool
        {
            throw new RuntimeException('no session');
        }

        public function default(): object
        {
            throw new RuntimeException('no brands');
        }
    });

    $entry = Entry::make()
        ->collection(EmailTemplateCollectionManager::HANDLE)
        ->slug('welcome')
        ->data(['title' => 'Welcome']);

    $entry->save();

    expect($entry)->toBeInstanceOf(EmailTemplateEntry::class);
    expect($entry->get(Brands::FIELD))->toBeNull();
});
