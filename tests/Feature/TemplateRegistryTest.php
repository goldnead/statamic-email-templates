<?php

use Goldnead\EmailTemplates\Contracts\EmailTemplateSource;
use Goldnead\EmailTemplates\CoreMails\CoreMails;
use Goldnead\EmailTemplates\Facades\EmailTemplateRegistry;
use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\EmailTemplates\Registry\TemplateRegistry;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * The registry: how an addon says which of its mails goes out when, and what
 * the Control Panel makes of that.
 */
beforeEach(function () {
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)?->queryEntries()->get()->each->delete();
});

function registerTeamsInvitation(): void
{
    // Exactly what a sibling does without importing a class from here.
    app('email-templates.registry')->register([
        'slug' => 'teams-invitation',
        'addon' => 'Teams',
        'trigger' => 'Jemand wird in ein Team eingeladen',
        'event' => 'Goldnead\Teams\Events\InvitationSent',
        'placeholders' => [
            'team.name' => ['label' => 'Name des Teams', 'example' => 'Sopran 1'],
            'url' => 'Link zur Einladung',
        ],
        'defaults' => fn () => [
            'title' => 'Team-Einladung',
            'subject' => 'Einladung in {{ team.name }}',
            'body' => '<p>Komm in {{ team.name }}: <a href="{{ url }}">annehmen</a></p>',
        ],
    ]);
}

it('is reachable by alias and facade, and knows the core mails', function () {
    expect(app('email-templates.registry'))->toBe(app(TemplateRegistry::class))
        ->and(EmailTemplateRegistry::has(CoreMails::PASSWORD_RESET))->toBeTrue()
        ->and(array_keys(EmailTemplateRegistry::all()))->toContain(...CoreMails::SLUGS);
});

it('describes a registered template as occasion and addon', function () {
    registerTeamsInvitation();

    $definition = EmailTemplateRegistry::find('teams-invitation');

    expect(EmailTemplateRegistry::describe('teams-invitation'))->toBe('Teams: Jemand wird in ein Team eingeladen')
        ->and($definition->event)->toBe('Goldnead\Teams\Events\InvitationSent')
        ->and($definition->placeholders()['url']['label'])->toBe('Link zur Einladung')
        ->and(EmailTemplateRegistry::examples('teams-invitation'))->toBe(['team' => ['name' => 'Sopran 1']]);
});

it('names the addon for a template that only an import source offers', function () {
    app()->bind('test.legacy-source', fn () => new class implements EmailTemplateSource
    {
        public function label(): string
        {
            return 'Accounts';
        }

        public function all(): array
        {
            return [EmailTemplateData::fromArray(['slug' => 'accounts-verify', 'title' => 'x'])];
        }
    });
    app()->tag(['test.legacy-source'], 'email-templates.sources');

    expect(app(TemplateRegistry::class)->describe('accounts-verify'))->toBe('Accounts')
        ->and(app(TemplateRegistry::class)->describe('site-own-mail'))->toBeNull();
});

it('imports the registered defaults in the chosen language', function () {
    registerTeamsInvitation();

    $this->artisan('email-templates:import', ['--source' => 'Statamic', '--locale' => 'de'])->assertSuccessful();

    $manager = app(EmailTemplateCollectionManager::class);
    $reset = $manager->findBySlug(CoreMails::PASSWORD_RESET);

    expect($reset)->not->toBeNull()
        ->and($reset->value('subject'))->toBe('Neues Passwort für {{ site_name }}')
        ->and($manager->findBySlug('teams-invitation'))->toBeNull();

    foreach (CoreMails::SLUGS as $slug) {
        expect($manager->findBySlug($slug))->not->toBeNull($slug);
    }

    $this->artisan('email-templates:import', ['--source' => 'Teams'])->assertSuccessful();
    expect($manager->findBySlug('teams-invitation')->value('subject'))->toBe('Einladung in {{ team.name }}');
});

it('leaves the core mails out of a plain import while they are switched off', function () {
    config()->set('email-templates.core_mails.enabled', false);
    $this->artisan('email-templates:import')->assertSuccessful();
    expect(app(EmailTemplateCollectionManager::class)->findBySlug(CoreMails::PASSWORD_RESET))->toBeNull();

    config()->set('email-templates.core_mails.enabled', true);
    $this->artisan('email-templates:import')->assertSuccessful();
    expect(app(EmailTemplateCollectionManager::class)->findBySlug(CoreMails::PASSWORD_RESET))->not->toBeNull();
});

it('ships English defaults too', function () {
    $this->artisan('email-templates:import', ['--source' => 'Statamic', '--locale' => 'en'])->assertSuccessful();

    expect(app(EmailTemplateCollectionManager::class)->findBySlug(CoreMails::PASSWORD_RESET)->value('subject'))
        ->toBe('Your new password for {{ site_name }}');
});

it('keeps the merge tag in the imported link, so the send fills it', function () {
    $this->artisan('email-templates:import', ['--source' => 'Statamic', '--locale' => 'de'])->assertSuccessful();

    $template = EmailTemplates::resolve(CoreMails::PASSWORD_RESET);
    $html = MergeVariables::apply($template->body, ['url' => 'https://example.com/reset/abc?x=1&y=2', 'user' => ['name' => 'Maria'], 'site_name' => 'ChoirLive', 'expires_in' => '1 Stunde']);

    expect($html)->toContain('href="https://example.com/reset/abc?x=1&amp;y=2"')
        ->and($html)->toContain('Hallo Maria')
        ->and($html)->not->toContain('{{');
});

it('previews a registered template with its examples', function () {
    registerTeamsInvitation();

    expect(MergeVariables::sampleDataFor('teams-invitation')['team']['name'])->toBe('Sopran 1')
        ->and(MergeVariables::sampleDataFor(CoreMails::PASSWORD_RESET)['url'])->toStartWith('https://example.com/')
        ->and(MergeVariables::sampleDataFor(null)['contact']['first_name'])->toBe('Maria');
});

/*
 * Preview must not know more than the send. A core password reset is filled
 * with user.*, url, expires_* and site_name — never with contact.*. A preview
 * that shows "Maria" for {{ contact.first_name }} lets an editor write a tag
 * that arrives in the inbox as a raw `{{ contact.first_name }}`.
 */
it('previews a registered template with nothing but its own placeholders and the site name', function () {
    config()->set('app.name', 'ChoirLive');
    $sample = MergeVariables::sampleDataFor(CoreMails::PASSWORD_RESET);

    expect($sample)->not->toHaveKey('contact')
        ->and($sample)->not->toHaveKey('unsubscribe_url')
        ->and($sample['site_name'])->toBe('ChoirLive')
        ->and($sample['user']['name'])->toBe('Maria Beispiel');
});

it('shows "Sent on" as plain text on the edit form and names the template\'s own placeholders', function () {
    $this->actingAsSuperUser();
    [$entry] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(slug: CoreMails::PASSWORD_RESET, title: 'Reset'));

    $html = html_entity_decode($this->get(cp_route('collections.entries.edit', [EmailTemplateCollectionManager::HANDLE, $entry->id()]))
        ->assertOk()
        ->getContent());

    // The page also carries the whole translation table, so the generic text
    // is looked for where a field's config would hold it.
    expect($html)->toMatch('/"handle":"sent_on"[^{}]*"type":"html"/')
        ->and($html)->not->toMatch('/"handle":"sent_on"[^{}]*"type":"textarea"/')
        ->and($html)->not->toMatch('/"instructions":"[^"]*contact\.first_name/')
        ->and($html)->toMatch('/"instructions":"[^"]*\{\{ user\.name \}\}/');
});

it('finds the template of the current site first', function () {
    Site::setSites([
        'de' => ['name' => 'Deutsch', 'url' => '/', 'locale' => 'de_DE'],
        'en' => ['name' => 'English', 'url' => '/en/', 'locale' => 'en_US'],
    ]);
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)->sites(['de', 'en'])->save();

    $de = Entry::make()->collection(EmailTemplateCollectionManager::HANDLE)->locale('de')->slug('willkommen')->data(['title' => 'Willkommen']);
    $de->save();
    $en = $de->makeLocalization('en')->data(['title' => 'Welcome']);
    $en->save();

    Site::setCurrent('en');
    expect(app(EmailTemplateCollectionManager::class)->findBySlug('willkommen')->value('title'))->toBe('Welcome');

    Site::setCurrent('de');
    expect(app(EmailTemplateCollectionManager::class)->findBySlug('willkommen')->value('title'))->toBe('Willkommen');
});

it('shows "Sent on" in the Control Panel listing', function () {
    $this->actingAsSuperUser();
    registerTeamsInvitation();

    [$own] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(slug: 'eigene', title: 'Eigene'));
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(slug: 'teams-invitation', title: 'Team-Einladung'));
    app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(slug: CoreMails::PASSWORD_RESET, title: 'Reset'));

    $response = $this->getJson(cp_route('collections.entries.index', EmailTemplateCollectionManager::HANDLE).'?columns=title,sent_on')
        ->assertOk();

    $rows = collect($response->json('data'))->keyBy('title');

    expect($rows['Team-Einladung']['sent_on'])->toBe('Teams: Jemand wird in ein Team eingeladen')
        ->and($rows['Reset']['sent_on'])->toBe('Statamic: '.__('email-templates::core_mails.password_reset.trigger'))
        ->and($rows['Eigene']['sent_on'])->toBeNull()
        ->and(collect($response->json('meta.columns'))->pluck('field'))->toContain('sent_on');
});

it('lists the placeholders on the edit form, without saving them into the blueprint', function () {
    $this->actingAsSuperUser();
    registerTeamsInvitation();
    [$entry] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(slug: 'teams-invitation', title: 'Team-Einladung'));

    $html = $this->get(cp_route('collections.entries.edit', [EmailTemplateCollectionManager::HANDLE, $entry->id()]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('template_placeholders')
        ->and($html)->toContain('team.name')
        ->and($html)->toContain('Teams: Jemand wird in ein Team eingeladen');

    // The file on disk, not the in-memory object: the in-memory one is the
    // same instance the listener extended for this request.
    $path = Blueprint::find('collections.'.EmailTemplateCollectionManager::HANDLE.'.email_template')->path();
    $yaml = file_get_contents($path);

    expect($yaml)->toContain('handle: body')
        ->and($yaml)->not->toContain('sent_on')
        ->and($yaml)->not->toContain('template_placeholders');
});
