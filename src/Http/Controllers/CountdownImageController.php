<?php

namespace Goldnead\EmailTemplates\Http\Controllers;

use Goldnead\EmailTemplates\Support\Countdown;
use Goldnead\EmailTemplates\Support\CountdownImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Serves the PNG behind `{{ countdown_image }}`.
 *
 * `GET /!/email-templates/countdown.png?until=…&w=…&bg=…&fg=…&label=…&signature=…`
 *
 * The route carries `signed` (an unsigned or tampered URL is a 403 before this
 * runs) and `throttle:60,1`. The response is cacheable for a minute, which is
 * exactly the resolution the picture has: it shows days, hours and minutes.
 *
 * Without GD there is nothing to draw with. That is a 404 for the client and a
 * warning in the log for the operator, because a mail full of broken image
 * icons is something the operator should hear about from the log before the
 * recipients tell them.
 */
class CountdownImageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! CountdownImage::available()) {
            Log::warning('email-templates: countdown image requested but GD is not available (or countdown.image is disabled). The {{ countdown_image }} tag needs ext-gd; use {{ countdown }} for a text countdown.');

            abort(404);
        }

        $countdown = Countdown::until($request->query('until'));

        if ($countdown === null) {
            abort(404);
        }

        $label = $request->query('label');
        $expired = $request->query('expired');

        $png = CountdownImage::render(
            $countdown,
            CountdownImage::width($request->query('w')),
            CountdownImage::colour($request->query('bg'), CountdownImage::DEFAULT_BG),
            CountdownImage::colour($request->query('fg'), CountdownImage::DEFAULT_FG),
            is_string($label) && $label !== '' ? mb_substr($label, 0, CountdownImage::MAX_LABEL) : null,
            is_string($expired) && $expired !== '' ? mb_substr($expired, 0, CountdownImage::MAX_LABEL) : null,
        );

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($png),
            'Cache-Control' => 'public, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
