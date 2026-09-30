<?php

namespace Modules\Setup\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Turns an uploaded JPG or PNG into the profile photo that's stored: the middle square of it, at most 800×800 px,
 * the way up the camera held it. It's drawn again rather than kept as uploaded, which drops what else the file
 * carried, like where a phone photo was taken, and anything that isn't image data.
 */
final class ProfilePhoto
{
    /**
     * The width and height a photo is stored at, or smaller if it was smaller.
     */
    public const Size = 800;

    /**
     * Larger images are refused before they're opened: each pixel takes memory while it's drawn.
     */
    private const MaxPixels = 50_000_000;

    /**
     * @return array{0: string, 1: string} the photo's bytes and their type, image/jpeg or image/png
     *
     * @throws ValidationException when the file isn't a JPG or PNG that can be read
     */
    public static function fromUpload(UploadedFile $file, string $field = 'photo'): array
    {
        $path = $file->getRealPath();
        $info = $path === false ? false : @getimagesize($path);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw ValidationException::withMessages([$field => 'Choose a JPG or PNG image.']);
        }

        [$width, $height, $type] = $info;

        if ($width * $height > self::MaxPixels) {
            throw ValidationException::withMessages([$field => 'This image is too large to use. Choose one under 50 megapixels.']);
        }

        $image = $type === IMAGETYPE_JPEG ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);

        if ($image === false) {
            throw ValidationException::withMessages([$field => "This image couldn't be read. Choose another JPG or PNG."]);
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = self::upright($image, $path);
        }

        $square = self::middleSquare($image, $type === IMAGETYPE_PNG);

        ob_start();
        $type === IMAGETYPE_PNG ? imagepng($square, null, 6) : imagejpeg($square, null, 85);

        return [ob_get_clean(), $type === IMAGETYPE_PNG ? 'image/png' : 'image/jpeg'];
    }

    /**
     * Phones save photos as the sensor saw them, with a note to turn them; turn the image as the note says.
     */
    private static function upright(GdImage $image, string $path): GdImage
    {
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $degrees = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $degrees === 0 ? $image : (imagerotate($image, $degrees, 0) ?: $image);
    }

    /**
     * The middle square of the image, shrunk to Size if it's larger. A PNG keeps its see-through parts.
     */
    private static function middleSquare(GdImage $image, bool $keepTransparency): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        $size = min($side, self::Size);

        $square = imagecreatetruecolor($size, $size);

        if ($keepTransparency) {
            imagealphablending($square, false);
            imagesavealpha($square, true);
            imagefill($square, 0, 0, imagecolorallocatealpha($square, 0, 0, 0, 127));
        }

        imagecopyresampled($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $size, $size, $side, $side);

        return $square;
    }
}
