<?php

namespace Goldnead\EmailTemplates\Actions;

use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Services\EmailTemplateResolver;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Statamic\Actions\Action;
use Statamic\Entries\Entry;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\User;
use Throwable;

/**
 * Send one template to a real inbox, from the listing row menu and from the
 * publish form's action menu.
 *
 * Live Preview renders the mail in a browser. A mail client is not a browser:
 * Outlook still lays out with Word, Gmail drops the `<style>` block, and a
 * dark-mode client repaints colours nobody chose. So the split-screen answers
 * "did I write what I meant" and cannot answer "does it survive the trip" —
 * which is the question this action exists for. Until 2.4.0 the only way to ask
 * it was to trigger the real thing: make a purchase, fire an automation.
 *
 * ── The one rule this class obeys
 *
 * A test that renders down its own path tests its own path. So the body is not
 * built here: {@see EmailTemplateResolver::forEntry()} produces it, and that
 * shares `decorate()` with the `resolve()` a sending addon calls — preheader
 * injection and layout wrapping happen in one place for both. Merge variables
 * then go through {@see MergeVariables::apply()}, the same routine, with the
 * same documented sample data the Live Preview uses. The test mail and the real
 * mail differ in the recipient and in the data fed in. In nothing else.
 *
 * ── Reporting a failure
 *
 * Core toasts an action's return value green whatever it says, and an exception
 * thrown out of `run()` becomes `success: false` — which the Control Panel then
 * *also* toasts green, because the HTTP request itself succeeded. Neither
 * channel can carry a failure. What can is a toast pushed from the server: it
 * rides in `_toasts` on the same response with its own severity, and
 * `message: false` is core's documented way of suppressing the green one that
 * would otherwise stand beside the red. Same arrangement as
 * statamic-payments' CancelSubscription.
 *
 * That matters more here than elsewhere. A mailer that refuses is the *normal*
 * failure — wrong credentials, a throttled relay, a From the provider will not
 * accept — and a green "sent" over a mail that never left is worse than no
 * button at all: the author would go on to publish a template nobody receives.
 *
 * ── Not queued
 *
 * `Mail::send()`, not `queue()`. A queued test would report success from the
 * moment the job was written, which is exactly the green-over-nothing this class
 * exists to avoid, and on a host with no running worker it would never arrive.
 * A test is worth the two seconds it blocks.
 */
class SendTestEmail extends Action
{
    protected static $handle = 'email_templates_send_test';

    /**
     * Confirm before running. This leaves the Control Panel and reaches a real
     * inbox; pressing the button again does not undo it.
     */
    protected $confirm = true;

    public static function title()
    {
        return __('email-templates::email_templates.test_send');
    }

    public function icon(): string
    {
        return 'mail';
    }

    /**
     * Actions are registered globally and offered on every listing in the
     * Control Panel. Without this, "Send test email" turns up in the bulk
     * toolbar of the site's Pages screen.
     *
     * Tested against the concrete `Statamic\Entries\Entry`, not the contract:
     * `Statamic\Contracts\Entries\Entry` is an empty marker interface and
     * carries neither `collectionHandle()` nor `slug()`. Not against
     * EmailTemplateEntry either, tempting as that is — a site whose
     * collection.yaml predates `entry_class` holds plain entries, and matching
     * on the subclass would make the button quietly vanish there with nothing
     * on screen to explain it.
     */
    public function visibleTo($item)
    {
        return $item instanceof Entry
            && $item->collectionHandle() === EmailTemplateCollectionManager::HANDLE;
    }

    /**
     * Whoever may edit the template may test it.
     *
     * Deliberately not a permission of its own. A new permission is off for
     * every existing role, so on upgrade the button would be invisible to the
     * very people who write the templates, with nothing on screen to say why.
     * `edit` is already the lock on this collection and it is the right one:
     * this sends the author's own draft to an address they type, out of a system
     * they can already make send by publishing.
     */
    public function authorize($user, $item)
    {
        return $user->can('edit', $item);
    }

    public function buttonText()
    {
        /** @translation */
        return __('email-templates::email_templates.test_send_button');
    }

    public function confirmationText()
    {
        return __('email-templates::email_templates.test_send_confirm');
    }

    /**
     * A dirty publish form is the expected state here — the author has just
     * typed something and wants to see it in a client. Core's warning is right
     * about the mechanism (this sends the *saved* entry, not the unsaved edits),
     * so it stays, but the wording has to name which of the two goes out. Nobody
     * guesses that from "you have unsaved changes".
     */
    public function dirtyWarningText()
    {
        return __('email-templates::email_templates.test_send_dirty');
    }

    protected function fieldItems()
    {
        return [
            'recipient' => [
                'type' => 'text',
                'input_type' => 'email',
                'display' => __('email-templates::email_templates.test_send_recipient'),
                'instructions' => __('email-templates::email_templates.test_send_recipient_instructions'),
                'validate' => 'required|email',
                'default' => User::current()?->email(),
            ],
        ];
    }

    public function run($items, $values)
    {
        $recipient = trim((string) ($values['recipient'] ?? ''));
        $resolver = app(EmailTemplateResolver::class);

        $sent = 0;
        $failures = [];

        foreach ($items as $entry) {
            try {
                $this->send($resolver, $entry, $recipient);
                $sent++;
            } catch (Throwable $e) {
                // Keyed by the template's own name, not the exception class: the
                // author picked these rows and needs to know which one did not go.
                $failures[(string) ($entry->value('title') ?: $entry->slug())] = $e->getMessage();
            }
        }

        if ($failures === []) {
            return trans_choice(
                'email-templates::email_templates.test_send_ok',
                $sent,
                ['count' => $sent, 'recipient' => $recipient]
            );
        }

        // `trans_choice`, not `__`: the string has a plural form, and `__` hands
        // back both halves with the `|` still between them. That is not a
        // cosmetic slip — the red toast is the only place a failure is reported,
        // and a toast reading "…could not be sent.|3 of 5 could not be sent."
        // is a message the author has to decode instead of read.
        Toast::error(trans_choice('email-templates::email_templates.test_send_failed', count($failures), [
            'template' => (string) array_key_first($failures),
            'reason' => (string) reset($failures),
            'failed' => count($failures),
            'total' => $items->count(),
        ]));

        // `false`, not `''` or `null`: core toasts `message || 'Action
        // completed'`, so anything falsy-but-not-false still puts a green
        // "Action completed" next to the red one.
        return ['message' => false];
    }

    /**
     * Render one entry the way a recipient gets it, then hand it to the mailer.
     *
     * Throws on anything that would make the test a lie — an empty body, a
     * mailer that refuses — so `run()` can name the template in the red toast.
     */
    protected function send(EmailTemplateResolver $resolver, Entry $entry, string $recipient): void
    {
        $template = $resolver->forEntry($entry);
        $sample = MergeVariables::sampleData();

        // Only the body is HTML. The subject and the plain-text part are not,
        // and escaping them would put a literal `&amp;` in front of the reader.
        // Same split the Live Preview makes.
        $subject = MergeVariables::apply($template->subject, $sample, escape: false);
        $html = MergeVariables::apply($template->body, $sample);
        $text = $template->plainText !== null
            ? MergeVariables::apply($template->plainText, $sample, escape: false)
            : null;

        // An empty body is a defect in the template, and a mail carrying nothing
        // arrives looking like a mailer problem instead. Say which it is, here,
        // where the author can still fix it.
        //
        // A picture counts as content. `strip_tags` throws an `<img>` away along
        // with everything else, so a flyer or a header graphic with no words
        // around it would read as empty to the test below. In 2.4.0 that was
        // accidentally harmless — the package dropped images on both the import
        // and the render path — and since 2.5.0 it would be plain wrong.
        if (trim(strip_tags($html)) === '' && stripos($html, '<img') === false) {
            throw new RuntimeException(
                (string) __('email-templates::email_templates.test_send_empty_body')
            );
        }

        // Sendable, but every client would show "(no subject)" and the author
        // would read that as a bug in the addon. Name the gap in the subject
        // line rather than refusing the send — the body is what they opened this
        // for.
        if ($subject === '') {
            $subject = (string) __('email-templates::email_templates.test_send_no_subject');
        }

        $sender = MergeVariables::previewSender();

        // `HtmlString` is Laravel's documented way of handing the mailer a
        // rendered string instead of a view name: `Mailer::renderView()` calls
        // `toHtml()` on anything Htmlable and only reaches the view factory
        // otherwise. It holds for the text part too — nothing is escaped on the
        // way through, the string is passed along as it stands.
        $view = ['html' => new HtmlString($html)];

        if ($text !== null && trim($text) !== '') {
            $view['text'] = new HtmlString($text);
        }

        $prefix = (string) config('email-templates.test_send.subject_prefix', '[Test] ');

        Mail::send($view, [], function (Message $message) use ($recipient, $subject, $prefix, $sender) {
            $message
                ->to($recipient)
                ->subject($prefix.$subject)
                ->from($sender['email'], $sender['name']);
        });
    }
}
