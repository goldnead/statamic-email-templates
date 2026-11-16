<?php

namespace Goldnead\EmailTemplates\Http\Controllers;

use Goldnead\EmailTemplates\Snapshots\SnapshotPreview;
use Goldnead\EmailTemplates\Snapshots\Snapshots;
use Goldnead\EmailTemplates\Support\Brands;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Shows one stored snapshot in the Control Panel.
 *
 * The neutral case, and only that: placeholder data, nobody's mail. A preview
 * for a *named* contact needs the contact, and contacts are not this addon's —
 * marketing has subscriptions, leadhub has contacts, automations has a run
 * context. Those consumers call
 * {@see SnapshotPreview::document()} from their own controller with their own
 * merge data; putting a `?contact=` parameter here would mean this package
 * guessing which of three contact stores a given id belongs to.
 *
 * Behind the CP's own authentication, and no further permission of its own
 * (consumers embed it as an iframe and bring their own permissions). What it
 * does enforce is the brand: only snapshots of the current brand are shown.
 */
class SnapshotPreviewController extends Controller
{
    public function __invoke(string $snapshot): Response
    {
        $model = Snapshots::find($snapshot);

        abort_if($model === null, 404);

        // A snapshot belongs to the brand it went out under. On a multi-brand
        // install the preview shows only the current brand's; another brand's
        // answers 404, the same as a missing id, so nothing is confirmed. A
        // snapshot recorded without a brand counts as the default brand's.
        // With no resolved brand nothing is shown rather than everything,
        // as in the template listing. Single-brand installs are untouched.
        if (Brands::active()) {
            $owner = ($model->brand !== null && $model->brand !== '') ? $model->brand : Brands::default();

            abort_if($owner === null || $owner !== Brands::current(), 404);
        }

        return response(SnapshotPreview::document($model))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
