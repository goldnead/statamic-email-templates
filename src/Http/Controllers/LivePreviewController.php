<?php

namespace Goldnead\EmailTemplates\Http\Controllers;

use Facades\Statamic\CP\LivePreview;
use Goldnead\EmailTemplates\Support\BardHtmlRenderer;
use Goldnead\EmailTemplates\Support\BrandedBodyRenderer;
use Goldnead\EmailTemplates\Support\EmailPreheader;
use Goldnead\EmailTemplates\Support\MergeVariables;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Renders the iframe contents for Statamic's native Live Preview.
 *
 * This is the collection's custom preview target. When an author opens Live
 * Preview in the `et_templates` publish form, Statamic tokenizes the *unsaved*
 * (live-edited) entry and loads this route in the split-screen iframe with a
 * `?token=…` query parameter.
 *
 * We pull that live entry via `LivePreview::item($request->statamicToken())`
 * and render it through the exact same path as a real send: the Bard body is
 * turned into email HTML by BardHtmlRenderer, then MergeVariables substitutes
 * the documented sample recipient data into subject and body. So what the
 * author sees while typing is what a recipient would receive, only with
 * placeholder data.
 */
class LivePreviewController extends Controller
{
    public function __construct(
        protected BardHtmlRenderer $renderer,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $token = $request->statamicToken();
        $entry = $token ? LivePreview::item($token) : null;

        if ($entry === null) {
            // No (or expired) token: the iframe was opened outside a live edit
            // session. Show a neutral placeholder rather than leaking anything.
            return $this->respond(
                (string) __('email-templates::email_templates.live_preview_target'),
                '<p style="color:#6b7280">'.e(__('email-templates::email_templates.live_preview_empty')).'</p>'
            );
        }

        $sample = MergeVariables::sampleData();

        $subject = MergeVariables::apply((string) ($entry->value('subject') ?? ''), $sample);
        $preview = MergeVariables::apply((string) ($entry->value('preview') ?? ''), $sample);
        $bodyHtml = MergeVariables::apply($this->renderer->render($entry->value('body')), $sample);

        // Mirror the send path: the hidden preheader rides at the very top of
        // the body. It stays invisible in the split-screen, but keeps preview
        // and real send producing identical HTML.
        $bodyHtml = EmailPreheader::prepend($bodyHtml, $preview);

        // When a layout resolves for this entry's `layout` choice (its own
        // handle, else the default_layout, else branded_layout), the wrapped
        // body is already a complete branded HTML document — return it verbatim
        // so the split-screen shows exactly what a recipient receives. Otherwise
        // fall back to the lean generic preview document.
        $layoutHandle = $entry->value('layout') !== null && (string) $entry->value('layout') !== ''
            ? (string) $entry->value('layout')
            : null;

        if (BrandedBodyRenderer::enabledFor($layoutHandle)) {
            return response(BrandedBodyRenderer::wrap($bodyHtml, $subject, $layoutHandle))
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        return $this->respond($subject, $bodyHtml);
    }

    /** Wrap the rendered email body in a lean HTML document for the iframe. */
    protected function respond(string $subject, string $bodyHtml): Response
    {
        $title = $subject !== ''
            ? $subject
            : (string) __('email-templates::email_templates.live_preview_target');

        $subjectLabel = (string) __('email-templates::email_templates.field_subject');

        $document = <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$this->e($title)}</title>
            <style>
                body { margin: 0; background: #f3f4f6; color: #111827; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
                .et-subject { padding: 12px 20px; background: #ffffff; border-bottom: 1px solid #e5e7eb; font-size: 13px; color: #6b7280; }
                .et-subject strong { color: #111827; font-weight: 600; }
                .et-body { max-width: 640px; margin: 24px auto; padding: 24px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; }
            </style>
        </head>
        <body>
            <div class="et-subject">{$this->e($subjectLabel)}: <strong>{$this->e($subject)}</strong></div>
            <div class="et-body">{$bodyHtml}</div>
        </body>
        </html>
        HTML;

        return response($document)->header('Content-Type', 'text/html; charset=utf-8');
    }

    protected function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
