<?php

use Carbon\Carbon;
use Goldnead\EmailTemplates\Support\Countdown;
use Goldnead\EmailTemplates\Support\CountdownImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config()->set('app.timezone', 'Europe/Berlin');
    app()->setLocale('de');
    Carbon::setTestNow(Carbon::parse('2026-09-28 14:00:00', 'Europe/Berlin'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Read the PNG header and IHDR width/height from image bytes. */
function pngDimensions(string $png): array
{
    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    return array_values(unpack('Nwidth/Nheight', substr($png, 16, 8)));
}

it('serves a PNG of the requested width for a signed URL', function () {
    $url = CountdownImage::url(Countdown::until('2026-10-01 18:00'), ['width' => '400']);

    $response = $this->get($url);

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'max-age=60, public');

    [$width, $height] = pngDimensions($response->getContent());

    expect($width)->toBe(400)
        ->and($height)->toBe(CountdownImage::height(400));
});

it('refuses the route without a signature', function () {
    $this->get(URL::route(CountdownImage::ROUTE, ['until' => '2026-10-01 18:00']))
        ->assertForbidden();
});

it('refuses a signed URL whose parameters were changed', function () {
    $url = CountdownImage::url(Countdown::until('2026-10-01 18:00'));

    $this->get($url.'&w=1200')->assertForbidden();
});

it('still renders after the moment has passed, showing zeros and the expired text', function () {
    $url = CountdownImage::url(Countdown::until('2026-10-01 18:00'));

    Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'Europe/Berlin'));

    $response = $this->get($url);
    $response->assertOk();

    // The picture cannot be read back as text, but it must differ from the
    // live rendering: a live countdown and an expired one are not the same
    // pixels. And the renderer's own view of the state is what drew it.
    $live = CountdownImage::render(Countdown::until('2026-10-01 18:00', Carbon::parse('2026-09-28 14:00', 'Europe/Berlin')), 600, 'ffffff', '111827');
    $expired = CountdownImage::render(Countdown::until('2026-10-01 18:00'), 600, 'ffffff', '111827');

    expect(Countdown::until('2026-10-01 18:00')->isExpired())->toBeTrue()
        ->and(Countdown::until('2026-10-01 18:00')->remaining()['total_seconds'])->toBe(0)
        ->and($response->getContent())->toBe($expired)
        ->and($expired)->not->toBe($live);
});

it('renders identical bytes while the remaining minute is the same, so the 60 s cache is honest', function () {
    // 14:00:00 → 3d 4h 0m 0s and 13:59:20 → 3d 4h 0m 40s both read "03 : 04 : 00".
    $a = CountdownImage::render(Countdown::until('2026-10-01 18:00'), 600, 'ffffff', '111827');
    Carbon::setTestNow(Carbon::parse('2026-09-28 13:59:20', 'Europe/Berlin'));
    $b = CountdownImage::render(Countdown::until('2026-10-01 18:00'), 600, 'ffffff', '111827');

    expect($a)->toBe($b);
});

it('answers 404 and logs when the image renderer is unavailable', function () {
    config()->set('email-templates.countdown.image', false);

    Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'GD'));

    $url = CountdownImage::url(Countdown::until('2026-10-01 18:00'));

    $this->get($url)->assertNotFound();
});

it('answers 404 for an until it cannot parse', function () {
    $this->get(URL::signedRoute(CountdownImage::ROUTE, ['until' => 'irgendwann']))
        ->assertNotFound();
});

it('is rate limited', function () {
    $url = CountdownImage::url(Countdown::until('2026-10-01 18:00'));

    $response = $this->get($url);

    $response->assertOk()->assertHeader('X-RateLimit-Limit', '60');
});
