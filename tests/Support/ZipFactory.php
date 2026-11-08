<?php

namespace Tests\Support;

use ZipArchive;

/**
 * Builds small gallery archives with generated images.
 */
final class ZipFactory
{
    /**
     * An image of the given format and size, filled with a colour derived from $seed so pages differ.
     */
    public static function image(string $format, int $width, int $height, int $seed = 0): string
    {
        $im = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($im, (50 + $seed * 40) % 256, (120 + $seed * 70) % 256, (200 + $seed * 30) % 256);
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $color);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, (int)($width / 4), (int)($height / 4), $white); // top left marker

        ob_start();
        switch ($format) {
            case 'png':
                imagepng($im);
                break;
            case 'gif':
                imagegif($im);
                break;
            case 'webp':
                imagewebp($im, null, 90);
                break;
            default:
                imagejpeg($im, null, 90);
        }
        imagedestroy($im);

        return ob_get_clean();
    }

    /**
     * The standard test gallery: mixed formats, names that need natural sorting, mixed case
     * extensions and a directory entry. Returns entry name => contents (directories: null),
     * in the order they are written to the zip (not page order).
     *
     * Page order (natural, case-insensitive): 1.jpg, 2.PNG, 3.webp, 4.gif, 10.jpg
     */
    public static function standardEntries(int $seed = 0): array
    {
        return array(
            'pages/' => null,
            '10.jpg' => self::image('jpeg', 640, 900, $seed + 5),
            '2.PNG' => self::image('png', 600, 800, $seed + 2),
            '1.jpg' => self::image('jpeg', 800, 1200, $seed + 1),
            '4.gif' => self::image('gif', 300, 400, $seed + 4),
            '3.webp' => self::image('webp', 500, 700, $seed + 3),
        );
    }

    public static function pageOrder(): array
    {
        return array('1.jpg', '2.PNG', '3.webp', '4.gif', '10.jpg');
    }

    public static function create(string $file, array $entries): string
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        if (is_file($file)) {
            unlink($file);
        }

        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Can't create $file");
        }
        foreach ($entries as $name => $data) {
            if ($data === null) {
                $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $zip->addFromString($name, $data);
            }
        }
        $zip->close();
        chmod($file, 0644);

        return $file;
    }
}
