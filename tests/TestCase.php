<?php

namespace Goldnead\EmailTemplates\Tests;

use Goldnead\EmailTemplates\EmailTemplatesServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * Core's AddonTestCase wires Testbench, Statamic's own providers and the addon
 * manifest in the order Statamic itself uses. That matters here: the addon's
 * commands, routes, views and translations are all booted from inside
 * `Statamic::booted()`, which only fires when the manifest knows the addon.
 * A hand-rolled Testbench case has to call `bootAddon()` by hand and then tests
 * a boot path that does not exist in production.
 */
abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = EmailTemplatesServiceProvider::class;

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->contentRoot());

        parent::tearDown();
    }

    /**
     * Runs after `defineEnvironment()`, so this is the last word on the config —
     * AddonTestCase sets the Stache directories in here too.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // The addon creates its collection inside `Statamic::booted()`, which
        // fires while the application boots — before AddonTestCase gets a chance
        // to redirect the Stache stores into `dev-null`. That first write would
        // otherwise land in `tests/__fixtures__/content` and survive the run, so
        // the next run would start from whatever the previous one left behind.
        // Point the content stores at a per-process temp directory instead;
        // every write after boot is redirected into `dev-null` as usual.
        $content = $this->contentRoot().'/collections';

        $app['config']->set('statamic.stache.stores.collections.directory', $content);
        $app['config']->set('statamic.stache.stores.entries.directory', $content);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /** Per-process content root, outside the repository. */
    protected function contentRoot(): string
    {
        return sys_get_temp_dir().'/email-templates-tests-'.getmypid();
    }

    /** Create and authenticate a Statamic super user for CP feature tests. */
    protected function actingAsSuperUser(): \Statamic\Contracts\Auth\User
    {
        $user = User::make()->email('admin@example.com')->makeSuper();
        $user->save();

        $this->actingAs($user);

        return $user;
    }
}
