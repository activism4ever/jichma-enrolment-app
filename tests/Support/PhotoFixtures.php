<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Generates real image uploads for photo tests.
 *
 * Uses GD to write an actual JPEG rather than a text file with a .jpg name,
 * because the upload path validates the true MIME type via finfo. A stub file
 * would pass a naive extension check but must be rejected.
 */
final class PhotoFixtures
{
    /** A valid JPEG of the given pixel dimensions. */
    public static function jpeg(int $width = 1200, int $height = 800): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 90, 150));
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return new UploadedFile($path, 'passport.jpg', 'image/jpeg', null, true);
    }

    public static function png(int $width = 600, int $height = 600): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'photo').'.png';

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 60));
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'passport.png', 'image/png', null, true);
    }

    /** A file that lies about its name: extension says .jpg, content is not an image. */
    public static function disguisedTextFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fake').'.jpg';
        file_put_contents($path, str_repeat('this is not an image. ', 500));

        return new UploadedFile($path, 'passport.jpg', 'image/jpeg', null, true);
    }

    /** Larger than the 5 MB limit, but still a genuine image. */
    public static function oversizedJpeg(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'big').'.jpg';

        // 3000x3000 of noise compresses poorly and reliably exceeds 5 MB.
        $width = 3000;
        $height = 3000;
        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x += 3) {
            for ($y = 0; $y < $height; $y += 3) {
                $colour = imagecolorallocate(
                    $image,
                    random_int(0, 255), random_int(0, 255), random_int(0, 255)
                );
                imagefilledrectangle($image, $x, $y, $x + 2, $y + 2, $colour);
            }
        }

        imagejpeg($image, $path, 100);
        imagedestroy($image);

        return new UploadedFile($path, 'huge.jpg', 'image/jpeg', null, true);
    }

    /** Empties the fake private disk before each photo test. */
    public static function fakeDisk(): void
    {
        Storage::fake('private');
    }
}