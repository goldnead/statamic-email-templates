<?php

namespace Goldnead\EmailTemplates\Support;

/**
 * Tags with parameters: `{{ countdown until="2026-10-01 18:00" }}`.
 *
 * {@see MergeVariables::apply()} replaces plain `{{ dotted.key }}` tags; it has
 * no notion of parameters. This is the second, narrower pass that runs after
 * it, over the handful of tags that compute something instead of looking a
 * value up. It is deliberately not a template language: a fixed list of tag
 * names, `key="value"` parameters, nothing else.
 *
 * Running *after* the plain pass is what lets a parameter come from a variable:
 * `{{ countdown until="{{ event.starts_at }}" }}` has its inner tag substituted
 * first and arrives here as a literal date. The bare form `until="event.starts_at"`
 * is resolved here against the same flattened data. Either way, a date that
 * cannot be resolved leaves the whole tag standing, for the same reason unknown
 * plain tags stay visible: the author should see it in the preview, not the
 * recipient in the inbox.
 *
 * Bard stores text HTML-escaped, so quotes arrive as `&quot;` / `&#039;` after
 * the Bard-to-HTML render. The parameter parser accepts those alongside the
 * literal quotes an HTML-string body or a subject line carries.
 */
class FunctionTags
{
    /** Tag name → handler method. Add a tag by adding a row and a method. */
    protected const TAGS = [
        'countdown' => 'countdown',
        'countdown_image' => 'countdownImage',
    ];

    /**
     * Resolve every function tag in $text.
     *
     * @param  array<string,mixed>  $flat  Dot-keyed scalars, as {@see MergeVariables::flatten()} produces them.
     */
    public static function apply(string $text, array $flat): string
    {
        if ($text === '' || ! str_contains($text, '{{')) {
            return $text;
        }

        $names = implode('|', array_map('preg_quote', array_keys(self::TAGS)));

        return (string) preg_replace_callback(
            '/\{\{\s*('.$names.')\b([^}]*)\}\}/',
            function (array $m) use ($flat) {
                $handler = self::TAGS[$m[1]];
                $params = self::params($m[2]);

                return self::$handler($params, $flat) ?? $m[0];
            },
            $text
        );
    }

    /**
     * Parse `key="value" other='value'` into an array. Values are unescaped
     * from Bard's HTML entities so `until` reads as a date, not as markup.
     *
     * @return array<string,string>
     */
    public static function params(string $raw): array
    {
        $raw = str_replace(['&quot;', '&#039;', '&#39;'], ['"', "'", "'"], $raw);

        preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $raw, $matches, PREG_SET_ORDER);

        $params = [];

        foreach ($matches as $match) {
            $params[$match[1]] = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
        }

        return $params;
    }

    /**
     * The `until` parameter as something {@see Countdown::until()} accepts:
     * a variable's value when the parameter names a known dot-path, otherwise
     * the literal string. Null when there is nothing to count down to.
     *
     * @param  array<string,mixed>  $flat
     */
    public static function resolveUntil(?string $until, array $flat): mixed
    {
        if ($until === null || trim($until) === '') {
            return null;
        }

        $until = trim($until);

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $until) && array_key_exists($until, $flat)) {
            $value = $flat[$until];

            return $value === null || $value === '' ? null : $value;
        }

        return $until;
    }

    /**
     * @param  array<string,string>  $params
     * @param  array<string,mixed>  $flat
     */
    protected static function countdown(array $params, array $flat): ?string
    {
        $countdown = Countdown::until(self::resolveUntil($params['until'] ?? null, $flat));

        if ($countdown === null) {
            return null;
        }

        $format = in_array($params['format'] ?? null, Countdown::FORMATS, true) ? $params['format'] : 'both';

        return $countdown->text($format, $params['expired'] ?? null);
    }

    /**
     * @param  array<string,string>  $params
     * @param  array<string,mixed>  $flat
     */
    protected static function countdownImage(array $params, array $flat): ?string
    {
        $countdown = Countdown::until(self::resolveUntil($params['until'] ?? null, $flat));

        if ($countdown === null) {
            return null;
        }

        return CountdownImage::tag($countdown, $params);
    }
}
