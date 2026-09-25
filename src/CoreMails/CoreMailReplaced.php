<?php

namespace Goldnead\EmailTemplates\CoreMails;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A core account mail went out from its template instead of core's text.
 *
 * Needed because the replacement is invisible to `NotificationSending` and
 * `NotificationSent` listeners registered for the original: the original
 * never reaches the mail channel, and those events arrive a second time for
 * {@see TemplatedCoreMail}. Whoever logs or forwards account mails listens
 * here, or to `NotificationSent` for `TemplatedCoreMail` (whose `replaces`
 * names the original class).
 *
 * No token, no link: the payload says which mail went to whom, not how to
 * use it.
 */
class CoreMailReplaced
{
    use Dispatchable;

    public function __construct(
        public readonly string $slug,
        public readonly string $originalClass,
        public readonly mixed $notifiable,
        public readonly string $recipient,
    ) {}
}
