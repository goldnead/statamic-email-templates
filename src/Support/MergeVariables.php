<?php

namespace Goldnead\EmailTemplates\Support;

/**
 * Merge-variable substitution for email templates.
 *
 * Templates author placeholders in Antlers-ish `{{ dotted.key }}` syntax
 * (e.g. `{{ contact.first_name }}`) in both the subject and the Bard body.
 * At send time the sending addon supplies real recipient data; the CP preview
 * supplies a documented *sample* data set so a template can be previewed
 * without a real contact.
 *
 * Both paths use exactly this class: `apply()` is the single substitution
 * routine, so what the preview renders and what a recipient receives differ
 * only in the data fed in, never in how tags are replaced.
 *
 * ── Default sample set (overridable via config `email-templates.preview.sample_data`)
 *   {{ contact.first_name }}   → Maria
 *   {{ contact.last_name }}    → Beispiel
 *   {{ contact.full_name }}    → Maria Beispiel
 *   {{ contact.email }}        → maria.beispiel@example.com
 *   {{ contact.salutation }}   → Hallo Maria
 *   {{ sender.name }}          → config('mail.from.name')
 *   {{ sender.email }}         → config('mail.from.address')
 *   {{ unsubscribe_url }}      → https://example.com/newsletter/abmelden
 *   {{ date }}                 → today, d.m.Y
 *
 * Unknown tags are left untouched so an author can *see* a missing/typo'd
 * variable in the preview rather than have it silently vanish.
 */
class MergeVariables
{
    /**
     * The documented default sample merge data, config-overridable.
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        $builtin = [
            'contact' => [
                'first_name' => 'Maria',
                'last_name' => 'Beispiel',
                'full_name' => 'Maria Beispiel',
                'email' => 'maria.beispiel@example.com',
                'salutation' => 'Hallo Maria',
            ],
            'sender' => [
                'name' => config('mail.from.name') ?: 'Adrian Goldner',
                'email' => config('mail.from.address') ?: 'info@example.com',
            ],
            'unsubscribe_url' => 'https://example.com/newsletter/abmelden',
            'date' => date('d.m.Y'),
        ];

        $configured = config('email-templates.preview.sample_data');

        return is_array($configured) && $configured !== []
            ? array_replace_recursive($builtin, $configured)
            : $builtin;
    }

    /**
     * Sample data with per-request overrides merged on top of the defaults.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    public static function sampleData(array $overrides = []): array
    {
        return $overrides === []
            ? self::defaults()
            : array_replace_recursive(self::defaults(), $overrides);
    }

    /**
     * Replace every `{{ dotted.key }}` tag in $text with its value from $data.
     * Tolerant of surrounding whitespace; leaves unknown tags in place.
     *
     * @param  array<string,mixed>  $data
     */
    public static function apply(string $text, array $data): string
    {
        if ($text === '') {
            return '';
        }

        $flat = self::flatten($data);

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m) use ($flat) {
                return array_key_exists($m[1], $flat) ? (string) $flat[$m[1]] : $m[0];
            },
            $text
        );
    }

    /**
     * Flatten a nested array to dot-keyed scalars: ['contact' => ['first_name' => 'x']]
     * becomes ['contact.first_name' => 'x'].
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $result += self::flatten($value, $full);
            } else {
                $result[$full] = $value;
            }
        }

        return $result;
    }
}
