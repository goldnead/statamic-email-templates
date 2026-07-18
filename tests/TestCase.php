<?php

namespace Goldnead\EmailTemplates\Tests;

use Goldnead\EmailTemplates\EmailTemplatesServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Statamic\Facades\User;
use Statamic\Providers\StatamicServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Statamic's AddonServiceProvider runs bootAddon() inside
        // Statamic::booted(...). orchestra/testbench doesn't fire those
        // callbacks, so ensure() / Nav registration never runs. Force it.
        $this->app->getProvider(EmailTemplatesServiceProvider::class)?->bootAddon();
    }

    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            EmailTemplatesServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('statamic.users.repository', 'file');

        // Point Statamic's Stache (collections/entries) at a per-process temp
        // dir so entry writes stay isolated between parallel runs and out of
        // any shared fixtures.
        $stacheRoot = sys_get_temp_dir().'/email-templates-stache-'.getmypid();
        $app['config']->set('statamic.stache.stores.collections.directory', $stacheRoot.'/content/collections');
        $app['config']->set('statamic.stache.stores.entries.directory', $stacheRoot.'/content/collections');
    }

    /**
     * Statamic registers addon CP routes inside Statamic::booted callbacks that
     * orchestra/testbench never fires. Mount them under the production `/cp`
     * URL prefix and `statamic.cp.` name prefix so `cp_route('email-templates.*')`
     * resolves exactly as it does in a real Control Panel.
     */
    protected function defineRoutes($router): void
    {
        $router->name('statamic.cp.')
            ->prefix('cp')
            ->group(__DIR__.'/../routes/cp.php');
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
