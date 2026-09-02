<?php

use Goldnead\EmailTemplates\Http\Controllers\CountdownImageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email Templates action routes
|--------------------------------------------------------------------------
|
| Mounted by Statamic under its action prefix (`/!/` by default) plus the
| addon slug, with the `statamic.` route-name prefix — the same arrangement
| core uses for `/!/forms/…`.
|
|   GET  /!/statamic-email-templates/countdown.png  → the PNG behind {{ countdown_image }}
|
| The URL is signed (URL::signedRoute, no expiry — a mail is opened whenever
| it is opened) and rate-limited. Query: until, w, bg, fg, label, expired.
*/

Route::get('countdown.png', CountdownImageController::class)
    ->middleware(['throttle:60,1', 'signed'])
    ->name('email-templates.countdown-image');
