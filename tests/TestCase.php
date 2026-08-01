<?php

namespace Goldnead\EmailTemplates\Tests;

use Goldnead\EmailTemplates\EmailTemplatesServiceProvider;
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

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
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
