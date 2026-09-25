<?php

namespace Goldnead\EmailTemplates\Listeners;

use Goldnead\EmailTemplates\CoreMails\CoreMailReplaced;
use Goldnead\EmailTemplates\CoreMails\CoreMails;
use Goldnead\EmailTemplates\CoreMails\TemplatedCoreMail;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Services\EmailTemplateResolver;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Statamic\Entries\Entry;
use Throwable;

/**
 * Sends Statamic's and Laravel's account mails from their templates.
 *
 * Laravel asks every `NotificationSending` listener before a channel sends,
 * and a listener that answers `false` stops that one channel. So for a core
 * notification on the mail channel this renders the template, sends it as a
 * {@see TemplatedCoreMail} to the same recipient, and answers `false`.
 *
 * Nothing changes unless all of these hold, which is what keeps an existing
 * install exactly as it was:
 *
 * - `email-templates.core_mails.enabled` is on (default off),
 * - the site has an entry with the template's slug,
 * - that entry is published. A draft is how an editor prepares a mail
 *   without it going live.
 *
 * If rendering fails the core mail goes out and the reason is logged: a
 * broken template must not cost anyone their password reset.
 */
class SendCoreMailsFromTemplates
{
    public function __construct(
        protected CoreMails $coreMails,
        protected EmailTemplateCollectionManager $collection,
        protected EmailTemplateResolver $resolver,
    ) {}

    public function handle(NotificationSending $event): ?bool
    {
        if ($event->channel !== 'mail' || ! config('email-templates.core_mails.enabled', false)) {
            return null;
        }

        if ($event->notification instanceof TemplatedCoreMail) {
            return null;
        }

        // Everything up to the send is in here, the link included: working
        // out a link can throw (a host's `createUrlUsing()`, a missing
        // `password.reset` route), and an exception leaving this listener
        // would stop the core mail as well. Nobody gets no mail because of a
        // template.
        try {
            $match = $this->coreMails->match($event->notifiable, $event->notification);

            if ($match === null) {
                return null;
            }

            $mail = $this->render($match['slug'], $match['variables'], $match['subject'], $event->notification::class);
        } catch (Throwable $e) {
            Log::warning('email-templates: a core mail could not be built from its template; the core mail was sent instead.', [
                'notification' => $event->notification::class,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if ($mail === null) {
            return null;
        }

        Notification::sendNow($event->notifiable, $mail, ['mail']);

        // The mail is out. A listener failing now must not turn that into an
        // error page, nor into a retried queue job that sends it twice, and
        // the `false` below must still reach Laravel or core sends as well.
        try {
            CoreMailReplaced::dispatch(
                $match['slug'],
                $event->notification::class,
                $event->notifiable,
                (string) ($match['variables']['user']['email'] ?? ''),
            );
        } catch (Throwable $e) {
            report($e);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    protected function render(string $slug, array $variables, ?string $subjectOverride, string $replaces): ?TemplatedCoreMail
    {
        $entry = $this->collection->findBySlug($slug);

        if (! $entry instanceof Entry || ! $entry->published()) {
            return null;
        }

        $template = $this->resolver->forEntry($entry);

        if (trim(strip_tags($template->body)) === '') {
            return null;
        }

        $subject = $subjectOverride ?? MergeVariables::apply($template->subject, $variables, escape: false);

        return new TemplatedCoreMail(
            slug: $slug,
            replaces: $replaces,
            subject: $subject,
            html: MergeVariables::apply($template->body, $variables),
            text: $template->plainText !== null ? MergeVariables::apply($template->plainText, $variables, escape: false) : null,
        );
    }
}
