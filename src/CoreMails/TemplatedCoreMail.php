<?php

namespace Goldnead\EmailTemplates\CoreMails;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * A core account mail, rendered from its template.
 *
 * Sent in place of the original notification. It carries the original's class
 * name, so a `NotificationSent` listener that logs account mails can still
 * tell a password reset from an activation.
 */
class TemplatedCoreMail extends Notification
{
    public function __construct(
        public readonly string $slug,
        public readonly string $replaces,
        public readonly string $subject,
        public readonly string $html,
        public readonly ?string $text = null,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * A Mailable rather than a MailMessage: the body is a finished HTML
     * string, and a Mailable takes one as it is (`html()`). A Mailable returned
     * from `toMail()` has to name its own recipient; it is the address the
     * original notification would have gone to.
     */
    public function toMail(mixed $notifiable): Mailable
    {
        $text = $this->text;

        $mail = (new Mailable)
            ->to($notifiable->routeNotificationFor('mail', $this))
            ->subject($this->subject)
            ->html($this->html);

        if ($text !== null && trim($text) !== '') {
            $mail->withSymfonyMessage(fn (Email $message) => $message->text($text));
        }

        return $mail;
    }
}
