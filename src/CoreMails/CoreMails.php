<?php

namespace Goldnead\EmailTemplates\CoreMails;

use Carbon\CarbonInterval;
use Goldnead\EmailTemplates\Listeners\SendCoreMailsFromTemplates;
use Goldnead\EmailTemplates\Registry\TemplateRegistry;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use ReflectionClass;
use ReflectionMethod;
use Statamic\Auth\Passwords\PasswordReset as PasswordResetManager;
use Statamic\Auth\User as StatamicUser;
use Statamic\Facades\User;
use Statamic\Notifications\ActivateAccount;
use Statamic\Notifications\ElevatedSessionVerificationCode;
use Statamic\Notifications\PasswordReset;
use Throwable;

/**
 * The account mails Statamic and Laravel send on their own, and how each one
 * becomes a template.
 *
 * Five notifications, none of which offers a hook to change its text except
 * Laravel's `ResetPassword::toMailUsing()` (which would cover one of five and
 * collide with a host app that already uses it). So instead of hooking each
 * one, {@see SendCoreMailsFromTemplates}
 * catches them at `NotificationSending`, one step before the mail channel,
 * and this class answers two questions for it: which template slug belongs
 * to this notification, and which values fill its placeholders.
 *
 * The link in each mail is computed by the same code core uses
 * (`PasswordReset::url()`, `ResetPassword::resetUrl()`,
 * `VerifyEmail::verificationUrl()`), so a site's `resetFormUrl()`,
 * `createUrlUsing()` or CP-form route keeps working unchanged.
 */
class CoreMails
{
    /** The addon label these mails are registered under. */
    public const ADDON = 'Statamic';

    public const PASSWORD_RESET = 'core-password-reset';

    public const PASSWORD_RESET_CP = 'core-password-reset-cp';

    public const ACTIVATE_ACCOUNT = 'core-activate-account';

    public const VERIFICATION_CODE = 'core-verification-code';

    public const VERIFY_EMAIL = 'core-verify-email';

    public const SLUGS = [
        self::PASSWORD_RESET,
        self::PASSWORD_RESET_CP,
        self::ACTIVATE_ACCOUNT,
        self::VERIFICATION_CODE,
        self::VERIFY_EMAIL,
    ];

    /** Announce the five core mails in the registry, like any addon would. */
    public static function register(TemplateRegistry $registry): void
    {
        $user = fn () => [
            'user.name' => ['label' => fn () => __('email-templates::core_mails.placeholders.user_name'), 'example' => 'Maria Beispiel'],
            'user.email' => ['label' => fn () => __('email-templates::core_mails.placeholders.user_email'), 'example' => 'maria.beispiel@example.com'],
            'site_name' => ['label' => fn () => __('email-templates::core_mails.placeholders.site_name'), 'example' => fn () => (string) config('app.name')],
        ];

        $url = fn (string $what) => ['url' => [
            'label' => fn () => __('email-templates::core_mails.placeholders.url_'.$what),
            'example' => 'https://example.com/'.$what.'?token=beispiel',
        ]];

        $expiry = [
            'expires_in' => ['label' => fn () => __('email-templates::core_mails.placeholders.expires_in'), 'example' => fn () => self::humanMinutes(60)],
            'expires_minutes' => ['label' => fn () => __('email-templates::core_mails.placeholders.expires_minutes'), 'example' => 60],
        ];

        $definitions = [
            self::PASSWORD_RESET => [
                'event' => PasswordReset::class,
                'placeholders' => $url('password-reset') + $user() + $expiry,
            ],
            self::PASSWORD_RESET_CP => [
                'event' => PasswordReset::class,
                'placeholders' => $url('password-reset') + $user() + $expiry,
            ],
            self::ACTIVATE_ACCOUNT => [
                'event' => ActivateAccount::class,
                'placeholders' => $url('activate') + $user() + $expiry + [
                    'message' => ['label' => fn () => __('email-templates::core_mails.placeholders.message'), 'example' => ''],
                ],
            ],
            self::VERIFICATION_CODE => [
                'event' => ElevatedSessionVerificationCode::class,
                'placeholders' => [
                    'code' => ['label' => fn () => __('email-templates::core_mails.placeholders.code'), 'example' => 'k3Xw9QbT2mLp7ZrV4nHs'],
                ] + $user(),
            ],
            self::VERIFY_EMAIL => [
                'event' => VerifyEmail::class,
                'placeholders' => $url('verify') + $user() + $expiry,
            ],
        ];

        foreach ($definitions as $slug => $definition) {
            $key = str_replace(['core-', '-'], ['', '_'], $slug);

            $registry->register($definition + [
                'slug' => $slug,
                'addon' => self::ADDON,
                'title' => fn () => __("email-templates::core_mails.{$key}.title"),
                'trigger' => fn () => __("email-templates::core_mails.{$key}.trigger"),
                'defaults' => fn () => [
                    'title' => __("email-templates::core_mails.{$key}.title"),
                    'subject' => __("email-templates::core_mails.{$key}.subject"),
                    'preview' => __("email-templates::core_mails.{$key}.preview"),
                    'body' => __("email-templates::core_mails.{$key}.body"),
                    'description' => __("email-templates::core_mails.{$key}.trigger"),
                ],
            ]);
        }
    }

    /**
     * The template and its values for a notification, or null when this is
     * not one of the five.
     *
     * @return array{slug: string, variables: array<string, mixed>, subject: string|null}|null
     */
    public function match(mixed $notifiable, Notification $notification): ?array
    {
        $variables = ['user' => $this->user($notifiable, $notification), 'site_name' => (string) config('app.name')];

        // ActivateAccount extends PasswordReset, so it has to be asked first.
        if ($notification instanceof ActivateAccount) {
            $message = trim((string) (ActivateAccount::body() ?? ''));
            $subject = ActivateAccount::subject();

            return [
                'slug' => self::ACTIVATE_ACCOUNT,
                'variables' => $variables + [
                    'url' => PasswordResetManager::url($this->token($notification), PasswordResetManager::BROKER_ACTIVATIONS),
                    'message' => $message,
                ] + $this->expiry($this->statamicBroker(PasswordResetManager::BROKER_ACTIVATIONS, 'web')),
                // The CP's "invite user" form lets the admin write a subject.
                // Whatever they typed for this one user beats the template.
                'subject' => is_string($subject) && trim($subject) !== '' ? $subject : null,
            ];
        }

        if ($notification instanceof PasswordReset) {
            $cp = $this->isCpReset();

            return [
                'slug' => $cp ? self::PASSWORD_RESET_CP : self::PASSWORD_RESET,
                'variables' => $variables + [
                    'url' => PasswordResetManager::url($this->token($notification), PasswordResetManager::BROKER_RESETS),
                ] + $this->expiry($this->statamicBroker(PasswordResetManager::BROKER_RESETS, $cp ? 'cp' : 'web')),
                'subject' => null,
            ];
        }

        if ($notification instanceof ResetPassword) {
            $url = $this->laravelResetUrl($notification, $notifiable);

            if ($url === null) {
                return null;
            }

            // Statamic's Eloquent user hands a CP reset to the model, which
            // sends this class rather than Statamic's. Same static decides.
            $cp = $this->isCpReset();

            return [
                'slug' => $cp ? self::PASSWORD_RESET_CP : self::PASSWORD_RESET,
                'variables' => $variables + ['url' => $url]
                    + $this->expiry($this->statamicBroker(PasswordResetManager::BROKER_RESETS, $cp ? 'cp' : 'web')),
                'subject' => null,
            ];
        }

        if ($notification instanceof VerifyEmail) {
            $minutes = (int) config('auth.verification.expire', 60);

            // Core computes the link before it asks a toMailUsing() callback,
            // so this does too; the callback then gets the last word.
            $url = (string) $this->callProtected($notification, 'verificationUrl', $notifiable);

            if (VerifyEmail::$toMailCallback) {
                $url = $this->verifyLinkOf(call_user_func(VerifyEmail::$toMailCallback, $notifiable, $url), $url);

                if ($url === null) {
                    return null;
                }
            }

            return [
                'slug' => self::VERIFY_EMAIL,
                'variables' => $variables + [
                    'url' => $url,
                    'expires_minutes' => $minutes,
                    'expires_in' => self::humanMinutes($minutes),
                ],
                'subject' => null,
            ];
        }

        if ($notification instanceof ElevatedSessionVerificationCode) {
            return [
                'slug' => self::VERIFICATION_CODE,
                'variables' => $variables + ['code' => $notification->verificationCode],
                'subject' => null,
            ];
        }

        return null;
    }

    /**
     * The link Laravel's own reset mail would carry.
     *
     * A host that customised that mail with `toMailUsing()` and not
     * `createUrlUsing()` has its link inside the callback, and often no
     * `password.reset` route at all — core never calls `resetUrl()` then.
     * So the callback is asked, and the button link of the MailMessage it
     * returns is used. A result without one (a code instead of a link, a
     * Mailable) means this mail is not one a template can stand in for:
     * null, and the host's mail goes out as it would have.
     */
    protected function laravelResetUrl(ResetPassword $notification, mixed $notifiable): ?string
    {
        // Laravel's own order: with toMailUsing() set, createUrlUsing() is
        // never asked (`ResetPassword::toMail()`), so it is not asked here.
        if (ResetPassword::$toMailCallback) {
            return $this->resetLinkOf(call_user_func(ResetPassword::$toMailCallback, $notifiable, $notification->token), $notification->token);
        }

        return (string) $this->callProtected($notification, 'resetUrl', $notifiable);
    }

    /**
     * The host button as a reset link, but only if it can be one: the token
     * must be in it. A "back to the homepage" button next to a link in the
     * text is not the reset link.
     */
    protected function resetLinkOf(mixed $message, string $token): ?string
    {
        $url = $this->actionUrlOf($message);

        return $url !== null && $token !== '' && str_contains($url, $token) ? $url : null;
    }

    /**
     * The host button as a verification link, but only if it is signed or
     * the very URL core computed.
     */
    protected function verifyLinkOf(mixed $message, string $coreUrl): ?string
    {
        $url = $this->actionUrlOf($message);

        return $url !== null && ($url === $coreUrl || str_contains($url, 'signature=')) ? $url : null;
    }

    /**
     * A warning when a host `toMailUsing()` keeps this template from ever
     * being used, or null. Asked by the Control Panel, not by a send.
     *
     * The callback is called with the viewing user and a sample token or
     * link, the same way the send would call it. A callback that returns a
     * Mailable, a MailMessage without a usable button, or throws, means every
     * real mail goes out as the host built it.
     */
    public function blockedBy(string $slug): ?string
    {
        if (array_key_exists($slug, $this->blocked)) {
            return $this->blocked[$slug];
        }

        [$class, $callback] = match ($slug) {
            self::PASSWORD_RESET, self::PASSWORD_RESET_CP => [ResetPassword::class, ResetPassword::$toMailCallback],
            self::VERIFY_EMAIL => [VerifyEmail::class, VerifyEmail::$toMailCallback],
            default => [null, null],
        };

        if ($callback === null) {
            return $this->blocked[$slug] = null;
        }

        $notifiable = $this->probeNotifiable();

        try {
            $ok = $class === ResetPassword::class
                ? $this->resetLinkOf(call_user_func($callback, $notifiable, 'probe-token-123'), 'probe-token-123') !== null
                : $this->verifyLinkOf(call_user_func($callback, $notifiable, $probe = url('/email/verify/1/probe?expires=1&signature=probe')), $probe) !== null;
        } catch (Throwable) {
            $ok = false;
        }

        return $this->blocked[$slug] = $ok ? null : class_basename($class);
    }

    /** @var array<string, string|null> */
    protected array $blocked = [];

    /** The viewing user as the host's model when there is one. */
    protected function probeNotifiable(): mixed
    {
        $user = User::current();

        if ($user !== null && method_exists($user, 'model') && is_object($user->model())) {
            return $user->model();
        }

        return $user ?? new AnonymousNotifiable;
    }

    protected function actionUrlOf(mixed $message): ?string
    {
        if (! $message instanceof MailMessage) {
            return null;
        }

        // Declared as a string, null until `action()` is called.
        $url = trim((string) $message->actionUrl);

        return $url === '' ? null : $url;
    }

    /**
     * Whether this reset was asked for on the CP's login screen.
     *
     * The CP's ForgotPasswordController points the link at its own form with
     * `PasswordReset::resetFormRoute('statamic.cp.password.reset')` before it
     * sends. That static is the one thing that tells the two apart — the
     * notification class is the same. A reset an admin triggers for a front-end
     * user from the Users screen keeps the front-end form, and so counts as a
     * website reset, which is what the recipient sees.
     */
    protected function isCpReset(): bool
    {
        try {
            $route = (new ReflectionClass(PasswordResetManager::class))->getProperty('route')->getValue();
        } catch (Throwable) {
            return false;
        }

        return $route === 'statamic.cp.password.reset';
    }

    /** Statamic's broker for resets or activations; `web`/`cp` when split. */
    protected function statamicBroker(string $purpose, string $side): string
    {
        $broker = config('statamic.users.passwords.'.$purpose);

        if (is_array($broker)) {
            $broker = $broker[$side] ?? reset($broker);
        }

        return (string) ($broker ?: config('auth.defaults.passwords'));
    }

    /**
     * @return array{expires_minutes: int, expires_in: string}
     */
    protected function expiry(string $broker): array
    {
        $minutes = (int) config("auth.passwords.{$broker}.expire", 60);

        return ['expires_minutes' => $minutes, 'expires_in' => self::humanMinutes($minutes)];
    }

    public static function humanMinutes(int $minutes): string
    {
        return CarbonInterval::minutes($minutes)->cascade()->locale(app()->getLocale())->forHumans();
    }

    /**
     * Name and address of whoever receives the mail: a Statamic user, or the
     * host's own Eloquent model (ChoirLive's `App\Models\User`), which Statamic
     * hands the notification to when the model defines the send method itself.
     *
     * @return array{name: string, email: string}
     */
    protected function user(mixed $notifiable, Notification $notification): array
    {
        if ($notifiable instanceof StatamicUser) {
            $email = (string) $notifiable->email();
            $name = (string) ($notifiable->name() ?? '');
        } else {
            $email = (string) (is_object($notifiable) && method_exists($notifiable, 'getEmailForPasswordReset')
                ? $notifiable->getEmailForPasswordReset()
                : data_get($notifiable, 'email', ''));
            $name = (string) (data_get($notifiable, 'name') ?? '');
        }

        // `Notification::route('mail', …)` has no model behind it: the
        // address is only in the route, which is also where the mail goes.
        if ($email === '' && is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')) {
            $email = $this->addressOf($notifiable->routeNotificationFor('mail', $notification));
        }

        // "Hallo ," reads broken. Greet an account without a name by its address.
        return ['name' => trim($name) !== '' ? $name : $email, 'email' => $email];
    }

    /**
     * A mail route is a string, or `[address => name]`, or a list of either.
     */
    protected function addressOf(mixed $route): string
    {
        if (is_string($route)) {
            return $route;
        }

        if (is_array($route) && $route !== []) {
            $key = array_key_first($route);

            return is_string($key) ? $key : $this->addressOf($route[$key]);
        }

        return '';
    }

    /** The token core passes into its notification's constructor. */
    protected function token(PasswordReset $notification): string
    {
        return (string) (new ReflectionClass(PasswordReset::class))->getProperty('token')->getValue($notification);
    }

    /**
     * Ask the notification for its own link, so every `createUrlUsing()` a
     * host registered is honoured exactly as in the core mail.
     */
    protected function callProtected(object $notification, string $method, mixed $notifiable): mixed
    {
        return (new ReflectionMethod($notification, $method))->invoke($notification, $notifiable);
    }
}
