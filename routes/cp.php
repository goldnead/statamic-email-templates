<?php

use Goldnead\EmailTemplates\Http\Controllers\PreviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email Templates CP Routes
|--------------------------------------------------------------------------
|
| Mounted by Statamic's AddonServiceProvider under the `/cp` URL prefix and
| the `statamic.cp.` route-name prefix, inside the authenticated CP middleware
| group. So `cp_route('email-templates.preview')` resolves in a real Control
| Panel, and both routes are auth-protected.
|
|   GET  email-templates/preview/{id?}  → the preview page (picker + iframe)
|   POST email-templates/preview        → JSON render (subject + HTML body)
*/

Route::get('email-templates/preview/{id?}', [PreviewController::class, 'show'])
    ->name('email-templates.preview');

Route::post('email-templates/preview', [PreviewController::class, 'preview'])
    ->name('email-templates.preview.render');
