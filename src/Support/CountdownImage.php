<?php

namespace Goldnead\EmailTemplates\Support;

use Goldnead\EmailTemplates\Http\Controllers\CountdownImageController;
use Illuminate\Support\Facades\URL;

/**
 * `{{ countdown_image }}`: a PNG that reads "dd : hh : mm", fetched afresh
 * each time a mail client loads it.
 *
 * The tag renders an `<img>` whose `src` is a signed URL on this addon's
 * action route ({@see CountdownImageController}).
 * The target moment, size and colours travel in the query string; the
 * signature (`URL::signedRoute`, no expiry, because the mail may be opened
 * long after it was sent) stops the endpoint from being a free image renderer
 * for anyone who finds it.
 *
 * Drawing is plain GD with no font file: the digits are seven-segment shapes
 * from filled rectangles, the caption uses GD's built-in bitmap font. That
 * keeps the addon free of a bundled TTF and of a FreeType dependency, and a
 * segment display is what people expect a countdown to look like anyway.
 *
 * Read the README before reaching for this: the image is right only at the
 * moment it is fetched, and mail clients fetch at different moments (Gmail's
 * proxy on each open, Apple Mail Privacy Protection once, in advance). The
 * text tag is right at send time and stays honest about being a snapshot.
 */
class CountdownImage
{
    public const ROUTE = 'statamic.email-templates.countdown-image';

    public const MIN_WIDTH = 200;

    public const MAX_WIDTH = 1200;

    public const DEFAULT_WIDTH = 600;

    public const DEFAULT_BG = 'ffffff';

    public const DEFAULT_FG = '111827';

    /** Longest caption the query string accepts; longer is cut, not refused. */
    public const MAX_LABEL = 80;

    /** Whether this installation can render the image at all. */
    public static function available(): bool
    {
        return (bool) config('email-templates.countdown.image', true) && extension_loaded('gd');
    }

    /**
     * The `<img>` tag for a countdown, as `{{ countdown_image }}` emits it.
     *
     * @param  array<string,string>  $params  `width`, `bg`, `fg`, `label`, `expired`, `alt`
     */
    public static function tag(Countdown $countdown, array $params = []): string
    {
        $width = self::width($params['width'] ?? $params['w'] ?? null);
        $height = self::height($width);

        $alt = $params['alt'] ?? (string) __('email-templates::countdown.image_alt', [
            'absolute' => $countdown->absolute(),
        ]);

        return sprintf(
            '<img src="%s" width="%d" height="%d" alt="%s" style="display:block;max-width:100%%;height:auto;border:0;">',
            e(self::url($countdown, $params)),
            $width,
            $height,
            e($alt)
        );
    }

    /**
     * The signed, absolute URL the image is served from. Parameters that are
     * at their default are left out so two templates with the same target
     * share one cacheable URL.
     *
     * @param  array<string,string>  $params
     */
    public static function url(Countdown $countdown, array $params = []): string
    {
        $query = ['until' => $countdown->until->toIso8601String()];

        $width = self::width($params['width'] ?? $params['w'] ?? null);
        if ($width !== self::DEFAULT_WIDTH) {
            $query['w'] = $width;
        }

        foreach (['bg' => self::DEFAULT_BG, 'fg' => self::DEFAULT_FG] as $key => $default) {
            $colour = self::colour($params[$key] ?? null, $default);
            if ($colour !== $default) {
                $query[$key] = $colour;
            }
        }

        foreach (['label', 'expired'] as $key) {
            if (isset($params[$key]) && trim($params[$key]) !== '') {
                $query[$key] = mb_substr(trim($params[$key]), 0, self::MAX_LABEL);
            }
        }

        return URL::signedRoute(self::ROUTE, $query);
    }

    /** Clamp a requested width into the range the renderer draws well. */
    public static function width(mixed $value): int
    {
        $width = is_numeric($value) ? (int) $value : self::DEFAULT_WIDTH;

        return max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width));
    }

    public static function height(int $width): int
    {
        return (int) round($width * 0.3);
    }

    /** A six-digit hex colour without `#`, or $default when the input is not one. */
    public static function colour(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $value = ltrim(trim($value), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $value)) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        return preg_match('/^[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $default;
    }

    /**
     * Render the PNG. Returns the image bytes.
     *
     * The caller has checked {@see available()}; without GD the functions
     * below do not exist.
     */
    public static function render(Countdown $countdown, int $width, string $bg, string $fg, ?string $label = null, ?string $expired = null): string
    {
        $width = self::width($width);
        $height = self::height($width);

        $image = imagecreatetruecolor($width, $height);

        $background = self::allocate($image, $bg);
        $foreground = self::allocate($image, $fg);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);

        $r = $countdown->remaining();
        $expiredNow = $countdown->isExpired();

        $cells = $expiredNow
            ? ['00', ':', '00', ':', '00']
            : [sprintf('%02d', $r['days']), ':', sprintf('%02d', $r['hours']), ':', sprintf('%02d', $r['minutes'])];

        $caption = $expiredNow
            ? ($expired ?? (string) __('email-templates::countdown.expired'))
            : ($label ?? (string) __('email-templates::countdown.image_label'));

        // Layout: digits fill the upper part, caption sits underneath. All
        // measures derive from the width so any size between MIN and MAX
        // keeps the same proportions.
        $padding = (int) round($width * 0.05);
        $captionScale = $width >= 480 ? 2 : 1;
        $captionHeight = 15 * $captionScale;
        $digitTop = $padding;
        $digitBottom = $height - $padding - $captionHeight - (int) round($height * 0.08);
        $digitHeight = max(10, $digitBottom - $digitTop);

        // Cells: six digits (each one unit wide), two colons (0.45), and a
        // 0.3 gap between neighbouring cells — 9 units across.
        $digitCount = array_sum(array_map(fn ($cell) => $cell === ':' ? 0 : strlen($cell), $cells));
        $units = $digitCount + 2 * 0.45 + ($digitCount + 2 - 1) * 0.3;
        $unit = ($width - 2 * $padding) / $units;
        $digitWidth = (int) floor($unit);
        $gap = (int) round($unit * 0.3);
        $colonWidth = (int) round($unit * 0.45);

        // A digit is taller than wide; if the height budget is the tighter
        // constraint, shrink the width to keep the 1:1.7 shape.
        if ($digitWidth * 1.7 > $digitHeight) {
            $digitWidth = (int) floor($digitHeight / 1.7);
        } else {
            $digitHeight = (int) round($digitWidth * 1.7);
        }

        $rowWidth = $digitCount * $digitWidth + 2 * $colonWidth + ($digitCount + 1) * $gap;
        $x = (int) round(($width - $rowWidth) / 2);
        $y = $digitTop + (int) round(($digitBottom - $digitTop - $digitHeight) / 2);
        $stroke = max(2, (int) round($digitWidth * 0.18));

        foreach ($cells as $cell) {
            if ($cell === ':') {
                self::colon($image, $x, $y, $colonWidth, $digitHeight, $foreground);
                $x += $colonWidth + $gap;

                continue;
            }

            foreach (str_split($cell) as $digit) {
                self::digit($image, (int) $digit, $x, $y, $digitWidth, $digitHeight, $stroke, $foreground);
                $x += $digitWidth + $gap;
            }
        }

        self::caption($image, $caption, $width, $height - $padding - $captionHeight, $captionScale, $foreground, $background);

        ob_start();
        imagepng($image, null, 6);
        $png = (string) ob_get_clean();

        imagedestroy($image);

        return $png;
    }

    /**
     * @param  \GdImage  $image
     */
    protected static function allocate($image, string $hex): int
    {
        [$r, $g, $b] = sscanf($hex, '%02x%02x%02x');

        return (int) imagecolorallocate($image, (int) $r, (int) $g, (int) $b);
    }

    /**
     * Seven-segment digit: segments a–g as in every LED display, drawn as
     * filled rectangles inside the box at ($x, $y) of $w × $h.
     *
     * @param  \GdImage  $image
     */
    protected static function digit($image, int $digit, int $x, int $y, int $w, int $h, int $t, int $colour): void
    {
        static $segments = [
            0 => 'abcdef',
            1 => 'bc',
            2 => 'abdeg',
            3 => 'abcdg',
            4 => 'bcfg',
            5 => 'acdfg',
            6 => 'acdefg',
            7 => 'abc',
            8 => 'abcdefg',
            9 => 'abcdfg',
        ];

        $mid = $y + intdiv($h, 2);
        $inset = intdiv($t, 2);

        $boxes = [
            'a' => [$x + $inset, $y, $x + $w - $inset, $y + $t],
            'b' => [$x + $w - $t, $y + $inset, $x + $w, $mid - $inset],
            'c' => [$x + $w - $t, $mid + $inset, $x + $w, $y + $h - $inset],
            'd' => [$x + $inset, $y + $h - $t, $x + $w - $inset, $y + $h],
            'e' => [$x, $mid + $inset, $x + $t, $y + $h - $inset],
            'f' => [$x, $y + $inset, $x + $t, $mid - $inset],
            'g' => [$x + $inset, $mid - intdiv($t, 2), $x + $w - $inset, $mid + intdiv($t, 2)],
        ];

        foreach (str_split($segments[$digit] ?? $segments[8]) as $segment) {
            [$x1, $y1, $x2, $y2] = $boxes[$segment];
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, $colour);
        }
    }

    /**
     * @param  \GdImage  $image
     */
    protected static function colon($image, int $x, int $y, int $w, int $h, int $colour): void
    {
        $dot = max(2, (int) round($w * 0.4));
        $cx = $x + intdiv($w - $dot, 2);

        imagefilledrectangle($image, $cx, $y + intdiv($h, 3) - intdiv($dot, 2), $cx + $dot, $y + intdiv($h, 3) + intdiv($dot, 2), $colour);
        imagefilledrectangle($image, $cx, $y + intdiv(2 * $h, 3) - intdiv($dot, 2), $cx + $dot, $y + intdiv(2 * $h, 3) + intdiv($dot, 2), $colour);
    }

    /**
     * Centred caption in GD's built-in font 5 (9×15 px), scaled up by an
     * integer factor for wide images. The built-in fonts speak Latin-1, so the
     * text is transliterated on the way in; a character with no Latin-1
     * equivalent becomes `?` rather than a broken glyph.
     *
     * @param  \GdImage  $image
     */
    protected static function caption($image, string $text, int $width, int $top, int $scale, int $colour, int $background): void
    {
        $latin = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        $latin = $latin === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $latin;
        $latin = (string) $latin;

        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($latin);
        $textHeight = imagefontheight($font);

        if ($textWidth === 0) {
            return;
        }

        $strip = imagecreatetruecolor($textWidth, $textHeight);
        imagefilledrectangle($strip, 0, 0, $textWidth, $textHeight, $background);
        imagestring($strip, $font, 0, 0, $latin, $colour);

        $dstW = $textWidth * $scale;
        $dstH = $textHeight * $scale;
        $dstX = (int) round(($width - $dstW) / 2);

        imagecopyresized($image, $strip, max(0, $dstX), $top, 0, 0, $dstW, $dstH, $textWidth, $textHeight);
        imagedestroy($strip);
    }
}
