<?php

use Goldnead\EmailTemplates\Http\Controllers\LivePreviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email Templates front-end routes
|--------------------------------------------------------------------------
|
| Mounted by Statamic's AddonServiceProvider at the site root, registered
| just before the catch-all FrontendController route (so this specific path
| always wins). It renders the iframe contents for the native CP Live Preview
| split-screen.
|
| Statamic appends a `?token=…` query parameter identifying the live-edited
| (unsaved) entry; the controller resolves it via `LivePreview::item()`. The
| path is referenced as the collection's preview target in
| EmailTemplateCollectionManager::LIVE_PREVIEW_ROUTE.
|
|   GET  email-templates/live-preview  → rendered email HTML for the iframe
*/

Route::get('email-templates/live-preview', LivePreviewController::class)
    ->name('email-templates.live-preview');
