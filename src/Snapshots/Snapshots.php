<?php

namespace Goldnead\EmailTemplates\Snapshots;

use Goldnead\EmailTemplates\Support\Brands;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\EmailTemplates\Support\LayoutResolver;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * What actually went out, held once for the whole suite.
 *
 * Three addons want to show a sent mail on a detail page — marketing (a
 * campaign), notifications (a notification) and automations (an e-mail node).
 * All three call this class. None of them stores mails of its own: a second
 * snapshot table in a consumer repo is the failure this layer exists to
 * prevent.
 *
 * ## What is stored, and what is not
 *
 * The **template**, with `{{ … }}` placeholders intact, exactly as it stood at
 * send time — subject, body, plain text, layout reference, brand, sender. Never
 * the rendered mail of a named person. That is the whole design: no personal
 * text is written here, so this table needs no deletion concept, no retention
 * rule and no answer to an erasure request. A consumer that writes rendered
 * output in here takes all three of those problems on for everybody, which is
 * why {@see looksRendered()} refuses the obvious cases outright.
 *
 * Recipients are not copied either. They already exist on the sending addon's
 * own tables — `marketing_messages` carries one row per recipient with its
 * `subscription_id`, and did before this layer existed. The snapshot is the
 * mail; the recipients stay where they are.
 *
 * ## One row per send, and what "one send" means
 *
 * A campaign to 800 people is one row. The key is
 * `(owner_type, owner_id, content_hash)`: a second send of an **unchanged**
 * template from the same owner reuses the row and bumps `send_count` and
 * `last_sent_at`; a send after the template was edited writes a new row, so the
 * history keeps both versions and each recipient list points at the version it
 * received.
 *
 * That rule is what keeps an automation honest. An e-mail node fires once per
 * contact, and a row per firing would be exactly the per-recipient store this
 * design rejects — with the node as the owner, ten thousand firings of an
 * unchanged node are one row.
 *
 * ## Consumers
 *
 * The dependency is optional in one direction only: consumers may be installed
 * without this addon, never the other way round. So a consumer never imports
 * anything from this package. It holds the class name as a string constant and
 * checks it:
 *
 * ```php
 * private const SNAPSHOTS = 'Goldnead\\EmailTemplates\\Snapshots\\Snapshots';
 *
 * if (class_exists(self::SNAPSHOTS)) {
 *     $class = self::SNAPSHOTS;
 *     $snapshot = $class::record('marketing:campaign', $campaign->id, [
 *         'subject' => $campaign->subject,   // with placeholders
 *         'body'    => $templateHtml,        // with placeholders
 *         'slug'    => $campaign->templateHandle,
 *     ]);
 * }
 * ```
 *
 * Nothing here throws at a caller during a send. {@see record()} answers `null`
 * when the addon is switched off, when the table is not there yet, or when
 * anything else goes wrong — and logs a warning, because a send that quietly
 * stops being recorded is worse than one that fails loudly.
 */
class Snapshots
{
    /**
     * The class name a consumer holds as a string.
     *
     * Frozen. It is copied into three other repositories as a literal, and a
     * rename would make all three fall back to "no snapshots" without an error
     * anywhere.
     */
    public const CLASS_NAME = 'Goldnead\\EmailTemplates\\Snapshots\\Snapshots';

    /** Memoised per process: `record()` runs on the send path. */
    protected static ?bool $tableExists = null;

    /**
     * Can a snapshot be written and shown right now?
     *
     * False means the addon is switched off or the migration has not run. A
     * consumer's detail page uses this to decide whether to offer the preview
     * at all, rather than linking at a route that would answer 404.
     */
    public static function available(): bool
    {
        if (! config('email-templates.snapshots.enabled', true)) {
            return false;
        }

        if (static::$tableExists === null) {
            try {
                static::$tableExists = Schema::hasTable('email_template_snapshots');
            } catch (\Throwable) {
                // No database configured at all. Answering "no" is the truthful
                // reading; the next process with a database answers again.
                return false;
            }
        }

        return static::$tableExists;
    }

    /** Forget the memoised table check. For tests that migrate mid-run. */
    public static function flush(): void
    {
        static::$tableExists = null;
    }

    /**
     * Record what is going out.
     *
     * Call it **once per send**, right before the mail is handed to the mailer
     * or the queue — with the template, not with the rendered mail of any one
     * recipient.
     *
     * `$ownerType` / `$ownerId` name the *sending thing*, never the recipient
     * and never the per-recipient row:
     *
     *   - `marketing:campaign` + the campaign id
     *   - `notifications:type` + the notification type handle
     *   - `automations:node` + `"{flow_uuid}:{node_uuid}"`
     *
     * Consumers that have a per-recipient or per-run row of their own store the
     * returned `id` on it, so a detail page finds its mail in one column.
     *
     * @param  EmailTemplateData|array{subject?: string, body?: string, html?: string, plain_text?: string|null, layout?: string|null, slug?: string|null, source?: string|null}  $template
     * @param  array{brand?: string|null, sender_name?: string|null, sender_email?: string|null, layout_view?: string|null, sent_at?: \DateTimeInterface|string|null}  $meta
     */
    public static function record(
        ?string $ownerType,
        int|string|null $ownerId,
        EmailTemplateData|array $template,
        array $meta = [],
    ): ?EmailTemplateSnapshot {
        if (! static::available()) {
            return null;
        }

        try {
            return static::write($ownerType, $ownerId, static::normalise($template), $meta);
        } catch (RuntimeException $e) {
            // The rendered-content guard. A programming error in a consumer, not
            // an operating condition — it has to be loud, and it must still not
            // take the send down with it.
            Log::warning('email-templates: refused to snapshot rendered mail. '.$e->getMessage(), [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('email-templates: could not record the send snapshot.', [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'exception' => $e,
            ]);

            return null;
        }
    }

    public static function find(int|string $id): ?EmailTemplateSnapshot
    {
        return static::available() ? EmailTemplateSnapshot::find($id) : null;
    }

    /**
     * Every version this owner has sent, newest first.
     *
     * @return Collection<int, EmailTemplateSnapshot>
     */
    public static function forOwner(string $ownerType, int|string $ownerId): Collection
    {
        if (! static::available()) {
            return new Collection;
        }

        return EmailTemplateSnapshot::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', (string) $ownerId)
            ->orderByDesc('last_sent_at')
            ->orderByDesc('id')
            ->get();
    }

    /** The version this owner sent most recently, or null. */
    public static function latestForOwner(string $ownerType, int|string $ownerId): ?EmailTemplateSnapshot
    {
        if (! static::available()) {
            return null;
        }

        return EmailTemplateSnapshot::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', (string) $ownerId)
            ->orderByDesc('last_sent_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The Control Panel preview URL for a snapshot, or null when there is none
     * to show.
     */
    public static function previewUrl(EmailTemplateSnapshot|int|string|null $snapshot): ?string
    {
        if ($snapshot === null) {
            return null;
        }

        $id = $snapshot instanceof EmailTemplateSnapshot ? $snapshot->getKey() : $snapshot;

        return static::available() ? cp_route('email-templates.snapshots.preview', ['snapshot' => $id]) : null;
    }

    /**
     * Does this look like the rendered mail of one recipient rather than the
     * template?
     *
     * Two markers, both of which a template cannot contain and a per-recipient
     * render always does: a Laravel signed URL, and the marketing open pixel
     * with its per-message UUID. That is not a complete detector and does not
     * pretend to be — a first name substituted into prose leaves no trace this
     * can find. It catches the mistake that actually happens, which is a
     * consumer passing the `html` it just built for a recipient instead of the
     * template it built it from, and it catches it before the row is written.
     */
    public static function looksRendered(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        // A signed route: `?signature=…` / `&signature=…`. Every per-recipient
        // link this family builds (unsubscribe, click tracking) is signed.
        if (preg_match('/[?&]signature=[a-f0-9]{16,}/i', $html) === 1) {
            return true;
        }

        // The open pixel, `/o/{uuid}.gif`, which exists only once a message row
        // does.
        return preg_match('#/o/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.gif#i', $html) === 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected static function write(?string $ownerType, int|string|null $ownerId, array $data, array $meta): EmailTemplateSnapshot
    {
        foreach (['subject', 'body', 'plain_text'] as $field) {
            if (is_string($data[$field] ?? null) && static::looksRendered($data[$field])) {
                throw new RuntimeException(
                    "The [{$field}] handed to Snapshots::record() carries a signed or per-message URL, "
                    .'so it is a rendered mail and not the template. Pass the template with its placeholders intact.'
                );
            }
        }

        $sentAt = $meta['sent_at'] ?? null;
        $sentAt = $sentAt instanceof \DateTimeInterface
            ? Carbon::instance($sentAt)
            : ($sentAt ? Carbon::parse($sentAt) : Carbon::now());

        $sender = static::sender($meta);

        $attributes = [
            'brand' => $meta['brand'] ?? Brands::current(),
            'template_slug' => $data['slug'],
            'template_source' => $data['source'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'plain_text' => $data['plain_text'],
            'layout' => $data['layout'],
            'layout_view' => $meta['layout_view'] ?? LayoutResolver::resolve($data['layout']),
            'sender_name' => $sender['name'],
            'sender_email' => $sender['email'],
        ];

        $key = [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId === null ? null : (string) $ownerId,
            'content_hash' => static::hash($attributes),
        ];

        $snapshot = EmailTemplateSnapshot::query()->where($key)->first();

        if ($snapshot) {
            // Same owner, same content: this is the same mail going out again,
            // not a second mail. Counting it is the whole record of the repeat.
            $snapshot->forceFill([
                'send_count' => $snapshot->send_count + 1,
                'last_sent_at' => $sentAt,
            ])->save();

            return $snapshot;
        }

        return EmailTemplateSnapshot::query()->create($key + $attributes + [
            'send_count' => 1,
            'first_sent_at' => $sentAt,
            'last_sent_at' => $sentAt,
        ]);
    }

    /**
     * The from-identity to record.
     *
     * The caller's, when it knows one — a sending addon resolves the real From
     * through brand-context and that is the address the recipients saw.
     * Otherwise the brand's preview sender, which is the same resolution one
     * step less exact. Recorded either way, because a brand that changes its
     * address next year must not rewrite what went out under the old one.
     *
     * @param  array<string, mixed>  $meta
     * @return array{name: string|null, email: string|null}
     */
    protected static function sender(array $meta): array
    {
        if (isset($meta['sender_email']) || isset($meta['sender_name'])) {
            return [
                'name' => $meta['sender_name'] ?? null,
                'email' => $meta['sender_email'] ?? null,
            ];
        }

        try {
            $sender = MergeVariables::previewSender();

            return ['name' => $sender['name'] ?? null, 'email' => $sender['email'] ?? null];
        } catch (\Throwable) {
            return ['name' => null, 'email' => null];
        }
    }

    /**
     * @param  EmailTemplateData|array<string, mixed>  $template
     * @return array<string, mixed>
     */
    protected static function normalise(EmailTemplateData|array $template): array
    {
        if ($template instanceof EmailTemplateData) {
            return [
                'slug' => $template->slug !== '' ? $template->slug : null,
                'source' => $template->source,
                'subject' => $template->subject,
                'body' => $template->body,
                'plain_text' => $template->plainText,
                'layout' => $template->layout,
            ];
        }

        return [
            'slug' => static::string($template['slug'] ?? $template['handle'] ?? null),
            'source' => static::string($template['source'] ?? null),
            'subject' => (string) ($template['subject'] ?? ''),
            'body' => (string) ($template['body'] ?? $template['html'] ?? ''),
            'plain_text' => static::string($template['plain_text'] ?? $template['text'] ?? null),
            'layout' => static::string($template['layout'] ?? null),
        ];
    }

    protected static function string(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : null;

        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * The fingerprint of a version.
     *
     * Over everything that decides what a reader sees, and nothing that does
     * not: the timestamps and the counter are excluded on purpose, or every
     * send would look like a new version.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function hash(array $attributes): string
    {
        return hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
