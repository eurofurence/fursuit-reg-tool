<?php

use App\Badges\EF28_Badge;
use App\Badges\EF29_Badge;
use App\Badges\EF30_Badge;
use App\Models\Badge\Badge;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Imagine\Image\Palette\RGB;

/**
 * The badge renderers, from the angle that took the print queue down.
 *
 * A print run renders every badge in one worker process, so anything a render leaves behind
 * is paid for again by every later badge in the same run. Rendering used to read photo
 * pixels through Imagine, whose RGB palette memoises every colour it is ever shown in a
 * static array - 28,000-57,000 objects per badge, 20-30MB, never freed. Runs died of memory
 * exhaustion around the fortieth card, were re-served by a second worker, and failed with
 * "has been attempted too many times".
 */
beforeEach(function () {
    Queue::fake();
    Storage::fake();
});

/** Count what Imagine has cached process-wide. */
function cachedColours(): int
{
    $colors = (new ReflectionClass(RGB::class))->getProperty('colors');
    $colors->setAccessible(true);

    return count($colors->getValue());
}

/** A photo with enough distinct colours to fill a colour cache, if anything still uses one. */
function photoFor(Badge $badge, int $seed): void
{
    $image = imagecreatetruecolor(400, 520);

    for ($x = 0; $x < 400; $x++) {
        for ($y = 0; $y < 520; $y++) {
            imagesetpixel($image, $x, $y, imagecolorallocate(
                $image,
                ($x + $seed) % 256,
                ($y * 2 + $seed) % 256,
                ($x + $y + $seed) % 256,
            ));
        }
    }

    ob_start();
    imagejpeg($image, null, 95);
    $bytes = ob_get_clean();
    imagedestroy($image);

    Storage::put($badge->fursuit->image, $bytes);
}

function renderableBadge(int $seed, string $renderer = 'EF30_Badge', bool $dual = false): Badge
{
    $badge = Badge::factory()->create([
        'custom_id' => '1234-'.$seed,
        'dual_side_print' => $dual,
    ]);

    $badge->fursuit->event->update(['badge_class' => $renderer]);
    photoFor($badge, $seed);

    return $badge->fresh(['fursuit.species', 'fursuit.event']);
}

it('renders badges without accumulating anything process-wide', function () {
    // The first render warms the fonts and the artwork, which is a one-off. What matters is
    // that the second and third leave nothing behind - a run is hundreds of badges long.
    (new EF30_Badge)->getPng(renderableBadge(1));

    $after = cachedColours();

    (new EF30_Badge)->getPng(renderableBadge(2));
    (new EF30_Badge)->getPng(renderableBadge(3));

    expect(cachedColours())->toBe($after);
})->group('rendering');

it('renders one page for a single-sided badge and two for a duplex one', function () {
    $pages = fn (string $pdf) => substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');

    expect($pages((new EF30_Badge)->getPdf(renderableBadge(4, dual: false))))->toBe(1)
        ->and($pages((new EF30_Badge)->getPdf(renderableBadge(5, dual: true))))->toBe(2);
})->group('rendering');

it('renders every badge design at the printer\'s own resolution', function (string $class) {
    $png = (new $class)->getPng(renderableBadge(6, class_basename($class)));

    $image = imagecreatefromstring($png);

    expect(imagesx($image))->toBe(1024)
        ->and(imagesy($image))->toBe(648);

    imagedestroy($image);
})->with([EF28_Badge::class, EF29_Badge::class, EF30_Badge::class])->group('rendering');
