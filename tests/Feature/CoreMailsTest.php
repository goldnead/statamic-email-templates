<?php

use Goldnead\EmailTemplates\CoreMails\CoreMailReplaced;
use Goldnead\EmailTemplates\CoreMails\CoreMails;
use Goldnead\EmailTemplates\CoreMails\TemplatedCoreMail;
use Goldnead\EmailTemplates\Registry\TemplateRegistry;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Statamic\Auth\Passwords\PasswordReset as PasswordResetManager;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\User;
use Statamic\Notifications\ActivateAccount;
use Statamic\Notifications\ElevatedSessionVerificationCode;
use Statamic\Notifications\PasswordReset;
use Symfony\Component\Mailer\SentMessage;

/**
 * Statamic's and Laravel's own account mails, sent from templates.
 *
 * Array transport, not `Notification::fake()`: the fake never fires
 * `NotificationSending`, which is the event the whole mechanism rides on, so
 * a faked suite would stay green with the listener deleted.
 */
beforeEach(function () {
    Collection::findByHandle(EmailTemplateCollectionManager::HANDLE)?->queryEntries()->get()->each->delete();

    config()->set('email-templates.branded_layout', null);
    config()->set('email-templates.core_mails.enabled', true);
    config()->set('mail.default', 'array');
    config()->set('app.name', 'ChoirLive');
    config()->set('auth.passwords.users.expire', 60);

    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');
    Mail::clearResolvedInstances();

    resetStatamicStatics();
});

afterEach(function () {
    resetStatamicStatics();
    ResetPassword::createUrlUsing(null);
    ResetPassword::toMailUsing(null);
    VerifyEmail::createUrlUsing(null);
    VerifyEmail::toMailUsing(null);
});

/** Core keeps these in statics; one test's CP reset must not leak into the next. */
function resetStatamicStatics(): void
{
    foreach (['url', 'route', 'redirect'] as $property) {
        (new ReflectionClass(PasswordResetManager::class))->getProperty($property)->setValue(null, null);
    }

    ActivateAccount::$subject = null;
    ActivateAccount::$body = null;
}

function coreMailsSent(): array
{
    return array_map(
        fn (SentMessage $sent) => $sent->getOriginalMessage(),
        Mail::getSymfonyTransport()->messages()->all()
    );
}

function coreTemplate(string $slug, array $overrides = []): Entry
{
    [$entry] = app(EmailTemplateCollectionManager::class)->upsert(new EmailTemplateData(
        slug: $slug,
        title: $overrides['title'] ?? $slug,
        subject: $overrides['subject'] ?? 'Neues Passwort, {{ user.name }}',
        body: $overrides['body'] ?? '<p>Hallo {{ user.name }} bei {{ site_name }}</p><p><a href="{{ url }}">Link</a></p><p>Gilt {{ expires_in }}.</p>',
    ));

    if (($overrides['published'] ?? true) === false) {
        $entry->published(false)->save();
    }

    return $entry;
}

function statamicUser(): Statamic\Contracts\Auth\User
{
    $user = User::make()->email('maria@example.com')->set('name', 'Maria Beispiel');
    $user->save();

    return $user;
}

it('leaves the core mail alone while the setting is off', function () {
    config()->set('email-templates.core_mails.enabled', false);
    coreTemplate(CoreMails::PASSWORD_RESET);

    statamicUser()->sendPasswordResetNotification('tok-123');

    $mails = coreMailsSent();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getSubject())->toBe(__('statamic::messages.reset_password_notification_subject'));
});

it('leaves the core mail alone when there is no template', function () {
    statamicUser()->sendPasswordResetNotification('tok-123');

    $mails = coreMailsSent();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getSubject())->toBe(__('statamic::messages.reset_password_notification_subject'));
});

it('leaves the core mail alone while the template is a draft', function () {
    coreTemplate(CoreMails::PASSWORD_RESET, ['published' => false]);

    statamicUser()->sendPasswordResetNotification('tok-123');

    expect(coreMailsSent()[0]->getSubject())->toBe(__('statamic::messages.reset_password_notification_subject'));
});

it('sends the Statamic password reset from its template, with the core link', function () {
    coreTemplate(CoreMails::PASSWORD_RESET);

    statamicUser()->sendPasswordResetNotification('tok-123');

    $mails = coreMailsSent();
    expect($mails)->toHaveCount(1);

    $html = $mails[0]->getHtmlBody();
    $expectedUrl = PasswordResetManager::url('tok-123', PasswordResetManager::BROKER_RESETS);

    expect($mails[0]->getSubject())->toBe('Neues Passwort, Maria Beispiel')
        ->and($mails[0]->getTo()[0]->getAddress())->toBe('maria@example.com')
        ->and($html)->toContain('Hallo Maria Beispiel bei ChoirLive')
        ->and($html)->toContain('href="'.e($expectedUrl).'"')
        ->and($expectedUrl)->toContain('tok-123')
        ->and($html)->toContain('Gilt 1 ');
});

it('tells the notification listeners which core mail it replaced', function () {
    coreTemplate(CoreMails::PASSWORD_RESET);
    $seen = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$seen) {
        $seen[] = $event->notification;
    });

    statamicUser()->sendPasswordResetNotification('tok-123');

    expect($seen)->toHaveCount(1)
        ->and($seen[0])->toBeInstanceOf(TemplatedCoreMail::class)
        ->and($seen[0]->replaces)->toBe(PasswordReset::class);
});

it('keeps the CP login reset on its own template and its own form', function () {
    coreTemplate(CoreMails::PASSWORD_RESET);

    // What the CP's ForgotPasswordController does before it sends.
    PasswordResetManager::resetFormRoute('statamic.cp.password.reset');

    statamicUser()->sendPasswordResetNotification('tok-cp');

    // Only the website template exists: the CP reset is left to core.
    expect(coreMailsSent()[0]->getSubject())->toBe(__('statamic::messages.reset_password_notification_subject'));

    coreTemplate(CoreMails::PASSWORD_RESET_CP, ['subject' => 'CP-Passwort']);
    Mail::getSymfonyTransport()->flush();

    statamicUser()->sendPasswordResetNotification('tok-cp');

    $mail = coreMailsSent()[0];
    expect($mail->getSubject())->toBe('CP-Passwort')
        ->and($mail->getHtmlBody())->toContain(e(route('statamic.cp.password.reset', 'tok-cp')));
});

it('sends the activation mail from its template with the CP invitation message', function () {
    coreTemplate(CoreMails::ACTIVATE_ACCOUNT, [
        'subject' => 'Willkommen bei {{ site_name }}',
        'body' => '<p><a href="{{ url }}">Aktivieren</a></p><p>{{ message }}</p>',
    ]);

    statamicUser()->sendActivateAccountNotification('act-1');
    $plain = coreMailsSent()[0];

    expect($plain->getSubject())->toBe('Willkommen bei ChoirLive')
        ->and($plain->getHtmlBody())->toContain(e(PasswordResetManager::url('act-1', PasswordResetManager::BROKER_ACTIVATIONS)));

    // The CP's "create user" form lets the admin write subject and message.
    ActivateAccount::subject('Deine Einladung');
    ActivateAccount::body('Wir proben dienstags <um 19 Uhr>.');
    Mail::getSymfonyTransport()->flush();

    statamicUser()->sendActivateAccountNotification('act-2');
    $invited = coreMailsSent()[0];

    expect($invited->getSubject())->toBe('Deine Einladung')
        ->and($invited->getHtmlBody())->toContain('Wir proben dienstags &lt;um 19 Uhr&gt;.');
});

it('sends the elevated session code from its template', function () {
    coreTemplate(CoreMails::VERIFICATION_CODE, ['subject' => 'Code', 'body' => '<p>Dein Code: {{ code }}</p>']);

    statamicUser()->notify(new ElevatedSessionVerificationCode('ABC123xyz'));

    expect(coreMailsSent()[0]->getHtmlBody())->toContain('Dein Code: ABC123xyz');
});

/** A host's own Eloquent-style user, like ChoirLive's App\Models\User. */
class CoreMailsHostUser
{
    use Notifiable;

    public function __construct(public string $email, public ?string $name = null) {}

    public function getKey(): int
    {
        return 7;
    }

    public function getEmailForPasswordReset(): string
    {
        return $this->email;
    }

    public function getEmailForVerification(): string
    {
        return $this->email;
    }
}

it('sends Laravel\'s own ResetPassword from the website template and keeps createUrlUsing', function () {
    coreTemplate(CoreMails::PASSWORD_RESET);
    ResetPassword::createUrlUsing(fn ($user, string $token) => 'https://app.example.com/reset/'.$token.'?email='.$user->email);

    Notification::sendNow(new CoreMailsHostUser('host@example.com'), new ResetPassword('lar-9'));

    $mail = coreMailsSent()[0];
    expect($mail->getTo()[0]->getAddress())->toBe('host@example.com')
        ->and($mail->getSubject())->toBe('Neues Passwort, host@example.com')
        ->and($mail->getHtmlBody())->toContain('https://app.example.com/reset/lar-9?email=host@example.com');
});

it('sends Laravel\'s VerifyEmail from its template', function () {
    coreTemplate(CoreMails::VERIFY_EMAIL, ['subject' => 'Bestätigen', 'body' => '<p><a href="{{ url }}">Bestätigen</a> {{ expires_in }}</p>']);
    VerifyEmail::createUrlUsing(fn ($user) => 'https://app.example.com/verify/7');

    Notification::sendNow(new CoreMailsHostUser('host@example.com', 'Hans'), new VerifyEmail);

    $mail = coreMailsSent()[0];
    expect($mail->getSubject())->toBe('Bestätigen')
        ->and($mail->getHtmlBody())->toContain('https://app.example.com/verify/7');
});

it('falls back to the core mail when the template cannot be rendered', function () {
    $entry = coreTemplate(CoreMails::PASSWORD_RESET);
    $entry->set('body', ['not' => 'a bard value'])->save();

    statamicUser()->sendPasswordResetNotification('tok-123');

    expect(coreMailsSent())->toHaveCount(1)
        ->and(coreMailsSent()[0]->getSubject())->toBe(__('statamic::messages.reset_password_notification_subject'));
});

it('does not touch notifications that are not core account mails', function () {
    coreTemplate(CoreMails::PASSWORD_RESET);

    Notification::sendNow(new CoreMailsHostUser('host@example.com'), new TemplatedCoreMail('x', 'y', 'Eigene Mail', '<p>eigene</p>'));

    expect(coreMailsSent()[0]->getSubject())->toBe('Eigene Mail');
});

/*
 * A host that customised Laravel's reset mail with toMailUsing() usually has
 * no `password.reset` route: its link lives in the callback. Asking
 * resetUrl() for the link then throws RouteNotFoundException, and before the
 * fix that exception left the listener and no mail went out at all.
 */
it('takes the link from a host toMailUsing() callback when there is no reset route', function () {
    coreTemplate(CoreMails::PASSWORD_RESET);
    ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
        ->subject('Host-Mail')
        ->action('Zurücksetzen', 'https://host.example.com/pw/'.$token));

    Notification::sendNow(new CoreMailsHostUser('host@example.com', 'Hans'), new ResetPassword('cb-1'));

    $mails = coreMailsSent();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getSubject())->toBe('Neues Passwort, Hans')
        ->and($mails[0]->getHtmlBody())->toContain('https://host.example.com/pw/cb-1');
});

it('leaves the host mail untouched when its toMailUsing() result carries no link', function () {
    coreTemplate(CoreMails::PASSWORD_RESET);
    ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)->subject('Host-Mail')->line('Code '.$token));

    Notification::sendNow(new CoreMailsHostUser('host@example.com'), new ResetPassword('cb-2'));

    $mails = coreMailsSent();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getSubject())->toBe('Host-Mail');
});

it('takes the verification link from a host VerifyEmail::toMailUsing() callback', function () {
    coreTemplate(CoreMails::VERIFY_EMAIL, ['subject' => 'Bestätigen', 'body' => '<p><a href="{{ url }}">Los</a></p>']);
    // Core asks for the link before the callback, so the test app needs one.
    VerifyEmail::createUrlUsing(fn () => 'https://example.com/verify/core');
    VerifyEmail::toMailUsing(fn ($user, string $url) => (new MailMessage)->subject('Host')->action('Los', 'https://host.example.com/v/1'));

    Notification::sendNow(new CoreMailsHostUser('host@example.com'), new VerifyEmail);

    expect(coreMailsSent()[0]->getHtmlBody())->toContain('https://host.example.com/v/1');
});

it('sends the core mail when working out the template values fails', function () {
    coreTemplate(CoreMails::PASSWORD_RESET);
    ResetPassword::createUrlUsing(fn () => throw new RuntimeException('kaputt'));
    ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)->subject('Host-Mail'));

    Notification::sendNow(new CoreMailsHostUser('host@example.com'), new ResetPassword('cb-3'));

    expect(coreMailsSent())->toHaveCount(1)
        ->and(coreMailsSent()[0]->getSubject())->toBe('Host-Mail');
});

/** A host model as ChoirLive has it: an Eloquent user Statamic wraps. */
class CoreMailsEloquentUser extends Illuminate\Foundation\Auth\User
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}

/*
 * Statamic's Eloquent user hands the reset to the model
 * (`Statamic\Auth\Eloquent\User::sendPasswordResetNotification()`), which
 * sends Laravel's ResetPassword, not Statamic's. The CP template has to catch
 * that too, with the link core would have sent.
 */
it('uses the CP template for an Eloquent user resetting on the CP login screen', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email');
        $table->string('password')->nullable();
        $table->timestamps();
    });
    Route::get('pw-reset/{token}', fn () => '')->name('password.reset');
    app('router')->getRoutes()->refreshNameLookups();

    coreTemplate(CoreMails::PASSWORD_RESET_CP, ['subject' => 'CP für {{ user.name }}']);
    $model = CoreMailsEloquentUser::create(['name' => 'Eva', 'email' => 'eva@example.com']);

    PasswordResetManager::resetFormRoute('statamic.cp.password.reset');
    (new Statamic\Auth\Eloquent\User)->model($model)->sendPasswordResetNotification('elo-1');

    $mail = coreMailsSent()[0];
    expect($mail->getSubject())->toBe('CP für Eva')
        ->and($mail->getHtmlBody())->toContain('pw-reset/elo-1');
});

it('greets an on-demand recipient by the routed address', function () {
    coreTemplate(CoreMails::PASSWORD_RESET);
    ResetPassword::createUrlUsing(fn ($user, string $token) => 'https://example.com/r/'.$token);

    Notification::route('mail', 'anon@example.com')->notify(new ResetPassword('anon-1'));

    $mail = coreMailsSent()[0];
    expect($mail->getTo()[0]->getAddress())->toBe('anon@example.com')
        ->and($mail->getSubject())->toBe('Neues Passwort, anon@example.com');
});

it('announces every replacement with its own event', function () {
    coreTemplate(CoreMails::PASSWORD_RESET);
    $seen = [];
    Event::listen(CoreMailReplaced::class, function (CoreMailReplaced $event) use (&$seen) {
        $seen[] = $event;
    });

    $user = statamicUser();
    $user->sendPasswordResetNotification('tok-ev');

    expect($seen)->toHaveCount(1)
        ->and($seen[0]->slug)->toBe(CoreMails::PASSWORD_RESET)
        ->and($seen[0]->originalClass)->toBe(PasswordReset::class)
        ->and($seen[0]->notifiable)->toBe($user)
        ->and($seen[0]->recipient)->toBe('maria@example.com');
});

it('keeps the occasion short and free of framework names', function () {
    foreach (CoreMails::SLUGS as $slug) {
        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);
            $definition = app(TemplateRegistry::class)->find($slug);

            expect(mb_strlen($definition->trigger()))->toBeLessThanOrEqual(40, "{$slug} {$locale}")
                ->and($definition->trigger().$definition->title())->not->toContain('Laravel');
        }
    }
});

it('sends the plain-text part of the template along', function () {
    $entry = coreTemplate(CoreMails::PASSWORD_RESET);
    $entry->set('plain_text', 'Link: {{ url }} & gut')->save();

    statamicUser()->sendPasswordResetNotification('tok-txt');

    expect(coreMailsSent()[0]->getTextBody())->toBe('Link: '.PasswordResetManager::url('tok-txt', PasswordResetManager::BROKER_RESETS).' & gut');
});
