<?php

namespace Goldnead\EmailTemplates\Snapshots;

use Goldnead\EmailTemplates\Support\BrandedBodyRenderer;
use Goldnead\EmailTemplates\Support\Brands;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Statamic\Statamic;

/**
 * Renders a stored snapshot back into a readable mail.
 *
 * The snapshot holds placeholders, so something has to be put in them at the
 * moment somebody looks. Two cases, and the page says out loud which one it is:
 *
 *  - **neutral** — the documented sample data. Nobody's mail, a specimen.
 *  - **a named contact** — the consumer passes today's values for that person.
 *    Today's, not the send day's: the mail that went out in March said whatever
 *    the contact record said in March, and nothing here reconstructs that. The
 *    banner says so rather than letting a reader assume otherwise.
 *
 * Nothing rendered here is stored. That is the point of the whole layer: the
 * substituted text exists for the length of one request.
 */
class SnapshotPreview
{
    /**
     * The mail itself, with values substituted — a complete HTML document when
     * the snapshot wore a layout, a body fragment otherwise.
     *
     * @param  array<string, mixed>  $mergeData  Empty means the sample set.
     */
    public static function renderMail(EmailTemplateSnapshot $snapshot, array $mergeData = []): string
    {
        return Brands::runFor($snapshot->brand, function () use ($snapshot, $mergeData) {
            $data = MergeVariables::sampleData(static::senderData($snapshot) + $mergeData);

            $body = MergeVariables::apply($snapshot->body, $data);

            // A snapshot recorded after the resolver decorated it is already a
            // complete document — the layout is baked in, and wrapping it again
            // would nest a second `<html>` inside the first. A snapshot recorded
            // before that step is a fragment and still needs its shell.
            //
            // Decided by looking at the string rather than by a stored flag: a
            // flag would be one more thing three consumers have to get right,
            // and the shell of an e-mail is not a subtle document to recognise.
            if (static::isCompleteDocument($body)) {
                return $body;
            }

            return BrandedBodyRenderer::wrap($body, MergeVariables::apply($snapshot->subject, $data, escape: false), $snapshot->layout);
        });
    }

    /**
     * The mail with the frame the Control Panel shows it in: a header that says
     * what this is, and the mail below it in its own iframe.
     *
     * The iframe is not decoration. An e-mail brings a full document with its
     * own `body` rules, and dropping that into the page would let it restyle
     * the notice that stands above it.
     *
     * @param  array<string, mixed>  $mergeData
     */
    public static function document(EmailTemplateSnapshot $snapshot, array $mergeData = []): string
    {
        $mail = static::renderMail($snapshot, $mergeData);

        $subject = MergeVariables::apply(
            $snapshot->subject,
            MergeVariables::sampleData(static::senderData($snapshot) + $mergeData),
            escape: false,
        );

        /*
         * Angezeigt in der Anzeige-Zeitzone, gespeichert bleibt UTC.
         *
         * Vorher formatierte diese Zeile in der Zeitzone der ANWENDUNG, waehrend
         * die Kopfzeile derselben Berichtsseite im Browser formatiert. Auf einem
         * Host mit `app.timezone = UTC` standen dort zwei Zeiten fuer dieselbe
         * Sendung, zwei Stunden auseinander (gemessen am 18.09.2026:
         * „Sent 18.9.2026, 20:03:50" oben, „Sent on 18.09.2026, 18:03" unten).
         *
         * Die Zeitzone der Anwendung zu drehen waere der falsche Hebel gewesen:
         * die Spalten halten UTC-Wanduhr ohne Kennung, und jeder bereits
         * gespeicherte Zeitstempel wuerde ab dann zwei Stunden zu frueh gelesen —
         * lautlos, ohne dass irgendwo etwas rot wird.
         *
         * `Statamic::displayTimezone()` ist die Schraube, die Statamic fuer genau
         * diese Frage mitbringt; ohne eigene Einstellung faellt sie auf
         * `app.timezone` zurueck, und dann aendert sich hier nichts.
         */
        $sentAt = $snapshot->last_sent_at
            ?->copy()
            ->setTimezone(Statamic::displayTimezone())
            ->format('d.m.Y, H:i') ?? '—';

        $notice = $mergeData === []
            ? (string) __('email-templates::email_templates.snapshot_notice_sample')
            : (string) __('email-templates::email_templates.snapshot_notice_today');

        $heading = (string) __('email-templates::email_templates.snapshot_heading', ['sent_at' => $sentAt]);
        $sender = trim(($snapshot->sender_name ?? '').' <'.($snapshot->sender_email ?? '').'>');
        $senderLabel = (string) __('email-templates::email_templates.snapshot_sender');
        $subjectLabel = (string) __('email-templates::email_templates.field_subject');
        $countLabel = (string) trans_choice('email-templates::email_templates.snapshot_send_count', $snapshot->send_count, ['count' => $snapshot->send_count]);

        $e = fn (string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$e($heading)}</title>
            <style>
                :root { color-scheme: light; }
                body { margin: 0; background: #f3f4f6; color: #111827; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
                .et-head { padding: 14px 20px; background: #ffffff; border-bottom: 1px solid #e5e7eb; }
                .et-head h1 { margin: 0 0 6px; font-size: 14px; font-weight: 600; }
                .et-meta { margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5; }
                .et-meta strong { color: #111827; font-weight: 600; }
                .et-notice { margin: 10px 0 0; padding: 8px 12px; background: #fefce8; border: 1px solid #fde68a; border-radius: 6px; font-size: 12px; color: #713f12; }
                .et-frame { display: block; width: 100%; height: calc(100vh - 150px); border: 0; background: #ffffff; }
            </style>
        </head>
        <body>
            <div class="et-head">
                <h1>{$e($heading)}</h1>
                <p class="et-meta">
                    {$e($subjectLabel)}: <strong>{$e($subject)}</strong><br>
                    {$e($senderLabel)}: {$e($sender)} &middot; {$e($countLabel)}
                </p>
                <p class="et-notice">{$e($notice)}</p>
            </div>
            <iframe class="et-frame" sandbox="" srcdoc="{$e($mail)}" title="{$e($subjectLabel)}"></iframe>
        </body>
        </html>
        HTML;
    }

    /**
     * The sender the snapshot recorded, as merge data, so `{{ sender.name }}`
     * resolves to the address the recipients actually saw and not to whatever
     * the brand is called today.
     *
     * @return array<string, mixed>
     */
    protected static function senderData(EmailTemplateSnapshot $snapshot): array
    {
        $sender = array_filter([
            'name' => $snapshot->sender_name,
            'email' => $snapshot->sender_email,
        ], fn ($value) => $value !== null && $value !== '');

        return $sender === [] ? [] : ['sender' => $sender];
    }

    protected static function isCompleteDocument(string $html): bool
    {
        $head = ltrim(substr($html, 0, 200));

        return stripos($head, '<!doctype') === 0 || stripos($head, '<html') === 0;
    }
}
