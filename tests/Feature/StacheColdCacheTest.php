<?php

use Goldnead\EmailTemplates\Entries\EmailTemplateEntry;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Illuminate\Contracts\Cache\Repository;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

/**
 * Point the Stache at a real, serializing cache store — the array store used by
 * the rest of the suite never serialises anything and would hide the defect.
 */
function stacheFileCache(): Repository
{
    config([
        'cache.stores.stache_cold_test' => [
            'driver' => 'file',
            'path' => sys_get_temp_dir().'/email-templates-stache-cache-'.getmypid(),
        ],
        'statamic.stache.cache_store' => 'stache_cold_test',
    ]);

    return Stache::cacheStore();
}

it('writes the addon entry class onto the cache unserialize allowlist', function () {
    expect(config('cache.serializable_classes'))
        ->toBeArray()
        ->toContain(EmailTemplateEntry::class);
});

it('reads a template entry back out of a cold Stache cache', function () {
    app(EmailTemplateCollectionManager::class)->ensure();

    $entry = Entry::make()
        ->collection(EmailTemplateCollectionManager::HANDLE)
        ->id('cold-cache-probe')
        ->slug('cold-cache-probe')
        ->data(['title' => 'Cold Cache Probe']);

    expect($entry)->toBeInstanceOf(EmailTemplateEntry::class);

    // Exactly what Statamic\Stache\Stores\BasicStore::getItem() does on a cold
    // cache: write the item, then read it back in a later request. Laravel
    // unserialises the payload against `cache.serializable_classes`; a class
    // that is missing there comes back as __PHP_Incomplete_Class and every
    // Stache read on the collection dies.
    $cache = stacheFileCache();
    $key = 'stache::items::entries::et_templates::cold-cache-probe';

    $cache->forever($key, $entry);
    $restored = $cache->get($key);
    $cache->forget($key);

    expect($restored)->toBeInstanceOf(EmailTemplateEntry::class);
});
