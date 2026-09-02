<?php

namespace Goldnead\EmailTemplates\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * The remaining time until a moment, as a template author wants to print it.
 *
 * An email is rendered once, at send time, and then it is paper: whatever the
 * countdown says stays said. So this class answers one question for one
 * instant — "how long from $now until $until" — and formats it in the app's
 * timezone and the current locale. `{{ countdown }}` prints the text form,
 * `{{ countdown_image }}` feeds the same numbers into a PNG (see
 * {@see CountdownImage}); both are resolved by {@see FunctionTags}.
 *
 * Relative text names the two most significant units that are non-zero
 * ("noch 3 Tage, 4 Stunden", "noch 2 Stunden, 5 Minuten", "noch 12 Minuten"),
 * because "3 Tage, 4 Stunden, 12 Minuten, 7 Sekunden" is noise in a mail that
 * will be read hours later anyway. Under a minute it says so in words rather
 * than counting seconds.
 */
class Countdown
{
    public const FORMATS = ['relative', 'absolute', 'both'];

    public function __construct(
        public readonly CarbonImmutable $until,
        public readonly CarbonImmutable $now,
    ) {}

    /**
     * Build a countdown towards $until, evaluated at $now (default: the clock,
     * which honours `Carbon::setTestNow()`).
     *
     * $until may be a DateTimeInterface or any string Carbon can parse. A naive
     * string ("2026-10-01 18:00") is read in `app.timezone`; a string carrying
     * its own offset keeps it and is then shown in `app.timezone`. Anything
     * unparseable yields null so the calling tag can stay visible in the
     * template instead of counting down to a guess.
     */
    public static function until(mixed $until, ?CarbonInterface $now = null): ?self
    {
        $timezone = self::timezone();

        if ($until instanceof DateTimeInterface) {
            $target = CarbonImmutable::instance($until)->setTimezone($timezone);
        } elseif (is_string($until) && trim($until) !== '') {
            try {
                $target = CarbonImmutable::parse(trim($until), $timezone)->setTimezone($timezone);
            } catch (\Throwable) {
                return null;
            }
        } else {
            return null;
        }

        $reference = $now !== null
            ? CarbonImmutable::instance($now)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);

        return new self($target, $reference);
    }

    public static function timezone(): string
    {
        $configured = config('app.timezone');

        return is_string($configured) && $configured !== '' ? $configured : 'UTC';
    }

    public function isExpired(): bool
    {
        return $this->until->lessThanOrEqualTo($this->now);
    }

    /**
     * Whole units left, floored, never negative.
     *
     * @return array{days:int,hours:int,minutes:int,seconds:int,total_seconds:int}
     */
    public function remaining(): array
    {
        $seconds = max(0, $this->until->getTimestamp() - $this->now->getTimestamp());

        return [
            'days' => intdiv($seconds, 86400),
            'hours' => intdiv($seconds % 86400, 3600),
            'minutes' => intdiv($seconds % 3600, 60),
            'seconds' => $seconds % 60,
            'total_seconds' => $seconds,
        ];
    }

    /** "noch 3 Tage, 4 Stunden" — or the expired text once the moment has passed. */
    public function relative(?string $expired = null): string
    {
        if ($this->isExpired()) {
            return $expired ?? (string) __('email-templates::countdown.expired');
        }

        $r = $this->remaining();
        $parts = [];

        if ($r['days'] > 0) {
            $parts[] = trans_choice('email-templates::countdown.days', $r['days'], ['count' => $r['days']]);
            if ($r['hours'] > 0) {
                $parts[] = trans_choice('email-templates::countdown.hours', $r['hours'], ['count' => $r['hours']]);
            }
        } elseif ($r['hours'] > 0) {
            $parts[] = trans_choice('email-templates::countdown.hours', $r['hours'], ['count' => $r['hours']]);
            if ($r['minutes'] > 0) {
                $parts[] = trans_choice('email-templates::countdown.minutes', $r['minutes'], ['count' => $r['minutes']]);
            }
        } elseif ($r['minutes'] > 0) {
            $parts[] = trans_choice('email-templates::countdown.minutes', $r['minutes'], ['count' => $r['minutes']]);
        } else {
            return (string) __('email-templates::countdown.less_than_a_minute');
        }

        return (string) __('email-templates::countdown.remaining', ['time' => implode(', ', $parts)]);
    }

    /** The target moment, formatted for the current locale in `app.timezone`. */
    public function absolute(): string
    {
        return $this->until->format((string) __('email-templates::countdown.absolute_format'));
    }

    /**
     * The text `{{ countdown }}` prints.
     *
     * @param  string  $format  One of {@see FORMATS}; anything else means `both`.
     * @param  string|null  $expired  Replaces the built-in "vorbei" once the moment has passed.
     */
    public function text(string $format = 'both', ?string $expired = null): string
    {
        return match ($format) {
            'relative' => $this->relative($expired),
            'absolute' => $this->absolute(),
            default => (string) __('email-templates::countdown.both', [
                'relative' => $this->relative($expired),
                'absolute' => $this->absolute(),
            ]),
        };
    }
}
