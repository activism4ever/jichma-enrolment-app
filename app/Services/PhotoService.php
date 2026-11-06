<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Passport photograph intake.
 *
 * Security posture, in order of application:
 *   1. Sniff the real MIME type with finfo. The browser-supplied content type
 *      and the file extension are both attacker-controlled and are ignored.
 *   2. Re-encode through GD. The bytes that end up on disk are produced by GD,
 *      not copied from the upload, so an image containing a PHP payload in a
 *      EXIF or appended segment cannot survive.
 *   3. Random filename. The original name never touches the filesystem, which
 *      removes a whole class of traversal and double-extension tricks.
 *   4. Private disk. Nothing under storage/app/private is web-reachable.
 */
class PhotoService
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB
    public const MAX_DIMENSION = 800;
    public const JPEG_QUALITY = 80;

    /** @var array<string> */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png'];

    private const DISK = 'private';
    private const DIRECTORY = 'photos';

    /**
     * Validate, resize and store an uploaded photograph.
     *
     * @return string|null The stored path relative to the private disk, or null
     *                     when no file was supplied.
     *
     * @throws \InvalidArgumentException on a rejected upload; callers let this
     *         propagate into a validation error, or catch it explicitly.
     */
    public function store(?UploadedFile $file): ?string
    {
        if (! $file || ! $file->isValid()) {
            return null;
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('The photograph must be 5 MB or smaller.');
        }

        $mime = $this->detectMime($file->getRealPath());

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new \InvalidArgumentException('The photograph must be a JPG or PNG image.');
        }

        $source = $this->loadImage($file->getRealPath(), $mime);

        try {
            $source = $this->orientFromExif($source, $file->getRealPath());

            // Scale so the longest side is at most MAX_DIMENSION. Only ever
            // shrinks: enlarging a small image would not add detail.
            $longest = max(imagesx($source), imagesy($source));

            if ($longest > self::MAX_DIMENSION) {
                $scale = self::MAX_DIMENSION / $longest;
                $newWidth = max(1, (int) round(imagesx($source) * $scale));
                $newHeight = max(1, (int) round(imagesy($source) * $scale));

                $resized = imagecreatetruecolor($newWidth, $newHeight);

                // Preserve transparency in the intermediate step, then flatten
                // to white so a transparent PNG does not become black in JPEG.
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled(
                    $resized, $source,
                    0, 0, 0, 0,
                    $newWidth, $newHeight,
                    imagesx($source), imagesy($source)
                );

                imagedestroy($source);
                $source = $resized;
            }

            $canvas = imagecreatetruecolor(imagesx($source), imagesy($source));
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle(
                $canvas, 0, 0, imagesx($source), imagesy($source), $white
            );
            imagecopy($canvas, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
            imagedestroy($source);

            $path = $this->directory().'/'.Str::random(40).'.jpg';

            ob_start();
            // imagejpeg writes the JPEG itself. No EXIF block is emitted, so
            // the original metadata (GPS coordinates, device, timestamps) is
            // dropped as a side effect of re-encoding rather than by an
            // explicit strip step.
            $written = imagejpeg($canvas, null, self::JPEG_QUALITY);
            $contents = ob_get_clean();
            imagedestroy($canvas);

            if (! $written || $contents === false || $contents === '') {
                throw new \RuntimeException('Failed to encode the photograph.');
            }

            Storage::disk(self::DISK)->put($path, $contents);

            return $path;
        } catch (\Throwable $e) {
            report($e);

            throw new \InvalidArgumentException('The photograph could not be processed.', 0, $e);
        } finally {
            if (is_resource($source)) {
                imagedestroy($source);
            }
        }
    }

    /**
     * Remove a stored photograph. Safe to call with null, so a controller can
     * pass the previous value unconditionally.
     */
    public function delete(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Could not delete photograph.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function exists(?string $path): bool
    {
        return filled($path) && Storage::disk(self::DISK)->exists($path);
    }

    public function contents(?string $path): ?string
    {
        if (! $this->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->get($path);
    }

    /**
     * Absolute filesystem path to a stored photograph, or null when it is not
     * readable there.
     *
     * Exists so a caller that is handing the file to something other than PHP —
     * the photo bundle's ZipArchive, for instance — can attach the file itself
     * instead of pulling every photograph's bytes through memory first.
     */
    public function absolutePath(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        $absolute = $disk->path($path);

        return is_readable($absolute) ? $absolute : null;
    }

    public function size(?string $path): ?int
    {
        if (! $this->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->size($path);
    }

    /**
     * Read the real content type from the file's own bytes.
     */
    public function detectMime(string $path): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($path) ?: null;
    }

    public function directory(): string
    {
        return self::DIRECTORY;
    }

    private function loadImage(string $path, string $mime): \GdImage
    {
        $image = $mime === 'image/png'
            ? @imagecreatefrompng($path)
            : @imagecreatefromjpeg($path);

        if ($image === false) {
            throw new \InvalidArgumentException('The photograph could not be read as an image.');
        }

        return $image;
    }

    /**
     * Honour the EXIF orientation tag so a portrait photo taken on a phone is
     * not displayed sideways. The tag is read here and then discarded when the
     * image is re-encoded.
     */
    private function orientFromExif(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);

        if (! is_array($exif) || ! isset($exif['Orientation'])) {
            return $image;
        }

        $rotate = match ((int) $exif['Orientation']) {
            3 => 270,  // 180 degrees
            6 => -90,  // 90 clockwise
            8 => 90,   // 90 counter-clockwise
            default => 0,
        };

        if ($rotate === 0) {
            return $image;
        }

        // imagerotate returns a new image rather than mutating in place.
        $rotated = imagerotate($image, $rotate, 0);
        imagedestroy($image);

        return $rotated;
    }
}