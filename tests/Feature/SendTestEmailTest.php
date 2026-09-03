<?php

use Goldnead\EmailTemplates\Actions\SendTestEmail;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Action;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\Entry;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * The Control Panel's "Send test email" action.
 *
 * ── Why `mail.default = array` and not `Mail::fake()`
 *
 * `MailFake::sendMail()` opens with `if (! $view instanceof Mailable) return;`
 * — it records Mailables and silently drops everything else. This action sends
 * a rendered string, not a Mailable, so under `Mail::fake()` every assertion
 * about what went out would be an assertion about nothing, and the suite would
 * stay green through a build that sent no mail at all.
 *
 * The array transport is the honest instrument: it keeps the real
 * `Symfony\Component\Mime\Email`, so these tests read the actual subject, From,
 * HTML part and text part off the message that would have gone down the wire.
 */
beforeEach(function () {
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)?->queryEntries()->get()->each->delete();

    config()->set('email-templates.branded_layout', null);
    config()->set('email-templates.test_send.subject_prefix', '[Test] ');
    config()->set('mail.from', ['address' => 'absender@example.com', 'name' => 'Absender']);
    config()->set('mail.default', 'array');

    // The MailManager caches a resolved mailer, and AddonTestCase may already
    // have touched it. Without this the config above arrives too late and the
    // messages land in whatever transport was resolved first.
    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');
    Mail::clearResolvedInstances();

    Toast::clear();
});

/** The messages the array transport collected, as Symfony Email objects. */
function sentEmails(): array
{
    return array_map(
        fn (SentMessage $sent) => $sent->getOriginalMessage(),
        Mail::getSymfonyTransport()->messages()->all()
    );
}

function makeTemplate(array $overrides = []): Statamic\Contracts\Entries\Entry
{
    [$entry] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: $overrides['slug'] ?? 'welcome',
        title: $overrides['title'] ?? 'Willkommen',
        subject: $overrides['subject'] ?? 'Hallo {{ contact.first_name }}',
        preview: $overrides['preview'] ?? '',
        body: $overrides['body'] ?? '<p>Schön, dass du da bist, {{ contact.first_name }}.</p>',
        plainText: $overrides['plainText'] ?? null,
    ));

    return $entry;
}

function runAction(array $entries, string $recipient = 'adrian@example.com'): mixed
{
    $items = collect($entries);

    return (new SendTestEmail)->items($items)->run($items, ['recipient' => $recipient]);
}

it('sends the template to the given recipient with merge variables filled in', function () {
    $entry = makeTemplate();

    $result = runAction([$entry]);

    $emails = sentEmails();
    expect($emails)->toHaveCount(1);

    $email = $emails[0];

    // Subject: prefixed, and the merge tag resolved from the documented sample
    // set — the same 'Maria' the Live Preview shows.
    expect($email->getSubject())->toBe('[Test] Hallo Maria')
        ->and($email->getTo()[0]->getAddress())->toBe('adrian@example.com')
        ->and($email->getHtmlBody())->toContain('Schön, dass du da bist, Maria.');

    // And the green toast names where it went, so the author checks the right
    // inbox.
    expect($result)->toContain('adrian@example.com');
});

it('sends from the address the live preview promised', function () {
    // Without brand-context installed the preview sender IS mail.from, so this
    // asserts the wiring rather than the brand resolution: the From on the wire
    // comes from MergeVariables::previewSender(), the one source the
    // split-screen also reads.
    runAction([makeTemplate()]);

    $from = sentEmails()[0]->getFrom()[0];

    expect($from->getAddress())->toBe('absender@example.com')
        ->and($from->getName())->toBe('Absender');
});

it('takes the same render path as a real send, preheader included', function () {
    $entry = makeTemplate(['preview' => 'Kurz vorab, {{ contact.first_name }}']);

    runAction([$entry]);

    $html = sentEmails()[0]->getHtmlBody();

    // The hidden preheader that EmailTemplateResolver::decorate() injects, with
    // its tokens resolved — proof the action went through the resolver rather
    // than rendering the Bard body on its own.
    expect($html)
        ->toContain('display:none')
        ->toContain('Kurz vorab, Maria');
});

it('attaches the plain-text part when the template has one', function () {
    $entry = makeTemplate(['plainText' => 'Hallo {{ contact.first_name }}, nur Text.']);

    runAction([$entry]);

    $email = sentEmails()[0];

    expect(trim($email->getTextBody()))->toBe('Hallo Maria, nur Text.')
        ->and($email->getHtmlBody())->toContain('Maria');
});

it('sends no text part when the template has none', function () {
    runAction([makeTemplate()]);

    expect(sentEmails()[0]->getTextBody())->toBeNull();
});

it('honours an emptied subject prefix', function () {
    config()->set('email-templates.test_send.subject_prefix', '');

    runAction([makeTemplate()]);

    expect(sentEmails()[0]->getSubject())->toBe('Hallo Maria');
});

it('marks a missing subject in the subject line rather than refusing to send', function () {
    $entry = makeTemplate(['subject' => '']);

    runAction([$entry]);

    // The suite runs in the fallback locale, so this reads the English string.
    expect(sentEmails())->toHaveCount(1)
        ->and(sentEmails()[0]->getSubject())->toContain('no subject');
});

it('refuses a template with an empty body and says so in red', function () {
    $entry = makeTemplate(['body' => '']);

    $result = runAction([$entry]);

    expect(sentEmails())->toBeEmpty();

    // `message: false` and not a string — anything falsy-but-not-false makes the
    // CP toast a green "Action completed" beside the red one.
    expect($result)->toBe(['message' => false]);

    $toasts = collect(Toast::all());

    expect($toasts)->toHaveCount(1)
        ->and($toasts->first()->toArray()['type'])->toBe('error')
        ->and($toasts->first()->toArray()['message'])->toContain('Willkommen');
});

/**
 * Documents a gap rather than a feature, so it stays visible.
 *
 * An image-only body is a real kind of email — a flyer, a header graphic — and
 * this package cannot carry one: `HtmlToBard` drops `<img>` on import (its own
 * docblock claims it keeps images) and `BardHtmlRenderer` renders a ProseMirror
 * `image` node as the empty string. The action's empty-body refusal is
 * therefore correct today, and this test is what fails when that changes,
 * pointing at the refusal that would then need an exception for pictures.
 */
it('cannot carry an image-only body, so the refusal is right for now', function () {
    $entry = makeTemplate(['body' => '<p><img src="https://example.com/flyer.png" alt="Flyer"></p>']);

    $result = runAction([$entry]);

    expect(sentEmails())->toBeEmpty()
        ->and($result)->toBe(['message' => false]);
});

it('reports a refusing mailer in red instead of a green sent', function () {
    // The normal failure in production: wrong credentials, a throttled relay, a
    // From the provider will not accept. It must not come back green.
    config()->set('mail.mailers.boom', ['transport' => 'boom']);
    config()->set('mail.default', 'boom');

    // Forget first, extend second. `Mail::extend()` registers the creator on the
    // *resolved* MailManager, so forgetting the instance afterwards throws the
    // extension away with it and the manager reports "Unsupported mail
    // transport [boom]" — which passes a sloppier assertion for the wrong reason.
    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');
    Mail::clearResolvedInstances();

    Mail::extend('boom', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new RuntimeException('Relay refused the message');
        }

        public function __toString(): string
        {
            return 'boom';
        }
    });

    $result = runAction([makeTemplate()]);

    expect($result)->toBe(['message' => false]);

    $toasts = collect(Toast::all());

    expect($toasts)->toHaveCount(1)
        ->and($toasts->first()->toArray()['type'])->toBe('error')
        ->and($toasts->first()->toArray()['message'])->toContain('Relay refused');
});

it('names the failing template when only one of several goes wrong', function () {
    $good = makeTemplate(['slug' => 'gut', 'title' => 'Gute Vorlage']);
    $bad = makeTemplate(['slug' => 'kaputt', 'title' => 'Kaputte Vorlage', 'body' => '']);

    $result = runAction([$good, $bad]);

    // The good one still went out; a bad row does not cancel the batch.
    expect(sentEmails())->toHaveCount(1)
        ->and($result)->toBe(['message' => false]);

    expect(collect(Toast::all())->first()->toArray()['message'])
        ->toContain('Kaputte Vorlage')
        ->not->toContain('Gute Vorlage');
});

it('is offered on email templates and nowhere else', function () {
    $template = makeTemplate();

    Collection::make('pages')->title('Pages')->save();
    $page = Entry::make()->collection('pages')->slug('startseite')->data(['title' => 'Startseite']);
    $page->save();

    $action = new SendTestEmail;

    // Actions are registered globally; without visibleTo() this turns up in the
    // bulk toolbar of the site's own Pages screen.
    expect($action->visibleTo($template))->toBeTrue()
        ->and($action->visibleTo($page))->toBeFalse();
});

it('is registered with the Control Panel', function () {
    expect(Action::all()->map->handle()->all())
        ->toContain('email_templates_send_test');
});

it('defaults the recipient field to the logged-in user', function () {
    $this->actingAsSuperUser();

    $fields = (new SendTestEmail)->fields();
    $recipient = $fields->get('recipient');

    expect($recipient)->not->toBeNull()
        ->and($recipient->defaultValue())->toBe('admin@example.com')
        // Without this the action is a send-to-anything endpoint: the recipient
        // arrives from the browser and goes straight into `Message::to()`.
        ->and($recipient->rules()['recipient'])->toContain('required')
        ->and($recipient->rules()['recipient'])->toContain('email');
});
