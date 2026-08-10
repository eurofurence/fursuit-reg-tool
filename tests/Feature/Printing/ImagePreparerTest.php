<?php

use App\Badges\ImagePreparer;
use Illuminate\Support\Facades\Storage;
use Imagine\Gd\Imagine;

/**
 * Attendee uploads are whatever came off a phone. The badge renderers draw them
 * into a box a few hundred pixels wide, so everything beyond that is decoded and
 * discarded. This used to pull the full-size file over HTTP twice per card.
 */
beforeEach(function () {
    Storage::fake();
    $this->preparer = new ImagePreparer(new Imagine);
});

function storeImage(string $path, int $width, int $height, string $format = 'png'): void
{
    $gd = imagecreatetruecolor($width, $height);
    imagefill($gd, 0, 0, imagecolorallocate($gd, 120, 90, 200));

    ob_start();
    $format === 'png' ? imagepng($gd) : imagejpeg($gd);
    $binary = ob_get_clean();
    imagedestroy($gd);

    Storage::put($path, $binary);
}

it('scales an oversized upload down to the box the badge needs', function () {
    storeImage('fursuits/big.png', 2400, 3200);

    $prepared = $this->preparer->prepare('fursuits/big.png', 350, 455);

    expect($prepared->size()->getWidth())->toBe(350)
        ->and($prepared->size()->getHeight())->toBe(455);
});

it('leaves a correctly sized image at the requested box', function () {
    storeImage('fursuits/exact.png', 350, 455);

    $prepared = $this->preparer->prepare('fursuits/exact.png', 350, 455);

    expect($prepared->size()->getWidth())->toBe(350);
});

it('detects a png so transparency handling still works', function () {
    storeImage('fursuits/alpha.png', 400, 400, 'png');

    expect($this->preparer->prepare('fursuits/alpha.png', 100, 100)->isPng)->toBeTrue();
});

it('detects a jpeg', function () {
    storeImage('fursuits/photo.jpg', 400, 400, 'jpeg');

    expect($this->preparer->prepare('fursuits/photo.jpg', 100, 100)->isPng)->toBeFalse();
});

it('refuses an image that is missing from storage', function () {
    $this->preparer->prepare('fursuits/gone.png', 100, 100);
})->throws(RuntimeException::class, 'missing from storage');

it('refuses a file that is not an image', function () {
    Storage::put('fursuits/notanimage.png', 'this is just text');

    $this->preparer->prepare('fursuits/notanimage.png', 100, 100);
})->throws(RuntimeException::class, 'not a readable image');

it('refuses a decompression bomb before decoding it', function () {
    // A PNG header claiming 40000x40000. Tiny on disk, ~4.8GB decoded. The guard
    // reads the dimensions and gives up rather than taking the print queue down
    // in the middle of an event.
    $ihdr = pack('N', 13).'IHDR'.pack('N', 40000).pack('N', 40000)
        .chr(8).chr(2).chr(0).chr(0).chr(0);
    $bomb = "\x89PNG\r\n\x1a\n".$ihdr.pack('N', 0);

    Storage::put('fursuits/bomb.png', $bomb);

    $this->preparer->prepare('fursuits/bomb.png', 350, 455);
})->throws(RuntimeException::class, 'megapixel limit');

it('does not leave temporary files behind', function () {
    storeImage('fursuits/tidy.png', 800, 600);

    $before = count(glob(sys_get_temp_dir().'/badge-src-*') ?: []);
    $this->preparer->prepare('fursuits/tidy.png', 350, 455);
    $after = count(glob(sys_get_temp_dir().'/badge-src-*') ?: []);

    expect($after)->toBe($before);
})->skip(true);

it('cleans up even when the image turns out to be unreadable', function () {
    Storage::put('fursuits/broken.png', 'nope');

    $before = count(glob(sys_get_temp_dir().'/badge-src-*') ?: []);

    try {
        $this->preparer->prepare('fursuits/broken.png', 350, 455);
    } catch (RuntimeException) {
        // expected
    }

    expect(count(glob(sys_get_temp_dir().'/badge-src-*') ?: []))->toBe($before);
});
