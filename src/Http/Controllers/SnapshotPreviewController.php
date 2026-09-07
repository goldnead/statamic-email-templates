<?php

namespace Goldnead\EmailTemplates\Http\Controllers;

use Goldnead\EmailTemplates\Snapshots\SnapshotPreview;
use Goldnead\EmailTemplates\Snapshots\Snapshots;
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
 * Behind the CP's own authentication, and no further permission of its own: a
 * snapshot is a template with placeholders in it, the same thing any
 * Control Panel user can already open under Content → E-Mail-Vorlagen.
 */
class SnapshotPreviewController extends Controller
{
    public function __invoke(string $snapshot): Response
    {
        $model = Snapshots::find($snapshot);

        abort_if($model === null, 404);

        return response(SnapshotPreview::document($model))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
