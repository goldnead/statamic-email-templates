<?php

namespace Goldnead\EmailTemplates\Support;

use Goldnead\BrandContext\Contracts\SenderIdentityResolver;
use Goldnead\BrandContext\Facades\BrandContext;

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
            'sender' => self::previewSender(),
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

        $text = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m) use ($flat) {
                return array_key_exists($m[1], $flat) ? (string) $flat[$m[1]] : $m[0];
            },
            $text
        );

        // Second pass, after the plain tags: `{{ countdown until="…" }}` and
        // friends take parameters, and a parameter may itself have been a
        // plain tag a moment ago. See FunctionTags for the tag list.
        return FunctionTags::apply($text, $flat);
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

    /**
     * The sender the preview shows.
     *
     * Until 2026-08-24 this read `config('mail.from.*')` unconditionally, so on
     * a multi-brand host every brand's template previewed with the same — and
     * for all but one brand, wrong — sender. Nothing was ever *sent* that way,
     * but a preview whose From is a lie is a preview you cannot use to check
     * the one thing you opened it for.
     *
     * The coupling is optional on purpose: this package does not require
     * statamic-brand-context, and an installation without it must keep working
     * unchanged. Hence class_exists rather than a composer dependency, which is
     * the same arrangement the rest of the family uses.
     *
     * @return array<string, string>
     */
    protected static function previewSender(): array
    {
        $fallback = [
            'name' => config('mail.from.name') ?: config('app.name') ?: 'Sender',
            'email' => config('mail.from.address') ?: 'info@example.com',
        ];

        if (! class_exists(BrandContext::class)) {
            return $fallback;
        }

        try {
            $resolver = app(SenderIdentityResolver::class);
            $identity = $resolver->resolve(null);

            // A refusing identity is exactly what the preview should surface —
            // but not by inventing an address. The fallback keeps the preview
            // readable; the refusal already speaks in the log, and the send path
            // is where it stops anything.
            if ($identity->fromAddress === null) {
                return $fallback;
            }

            return [
                'name' => $identity->fromName ?: $fallback['name'],
                'email' => $identity->fromAddress,
            ];
        } catch (\Throwable) {
            // brand-context present but not bootable here (no brand, no
            // container binding, a driver that needs a database). The preview is
            // not worth an exception.
            return $fallback;
        }
    }
}
