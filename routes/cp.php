<?php

use Goldnead\EmailTemplates\Http\Controllers\SnapshotPreviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email Templates Control Panel routes
|--------------------------------------------------------------------------
|
| Mounted by Statamic's AddonServiceProvider under the CP prefix and behind
| the CP's authentication middleware. Route names get the `statamic.cp.`
| prefix, so this one is addressed as
| `cp_route('email-templates.snapshots.preview', ['snapshot' => $id])`.
|
| One route, and it belongs here rather than in each consumer: marketing,
| notifications and automations all want the same thing — the mail that went
| out, shown in a panel next to their own numbers. They put this URL in an
| iframe and are done.
|
|   GET  /cp/email-templates/snapshots/{snapshot}/preview
*/

Route::get('email-templates/snapshots/{snapshot}/preview', SnapshotPreviewController::class)
    ->name('email-templates.snapshots.preview');
