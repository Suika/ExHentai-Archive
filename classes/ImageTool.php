<?php

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Vips\Driver as VipsDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Thin wrapper around Intervention Image, configured to behave like the previously used
 * PHPImageWorkshop: no automatic EXIF rotation and only the first frame of animated images.
 *
 * Engine: libvips (via FFI) when available - it streams the image while resizing and needs far
 * less memory than GD for very large gallery pages. Falls back to GD otherwise.
 * The IMAGE_DRIVER environment variable ("vips" or "gd") forces an engine.
 */
class ImageTool
{
    private static $manager;
    private static $driverName;

    public static function manager()
    {
        if (!self::$manager) {
            $options = array('autoOrientation' => false, 'decodeAnimation' => false);

            if (self::vipsAvailable()) {
                // Every request processes a different page, so libvips' operation cache never hits;
                // disable it so long-lived php-fpm workers don't hold on to decoded images.
                \Jcupitt\Vips\Config::cacheSetMax(0);

                self::$manager = new ImageManager(VipsDriver::class, ...$options);
                self::$driverName = 'vips';
            } else {
                self::$manager = new ImageManager(GdDriver::class, ...$options);
                self::$driverName = 'gd';
            }
        }

        return self::$manager;
    }

    /**
     * @return string "vips" or "gd"
     */
    public static function driverName()
    {
        self::manager();

        return self::$driverName;
    }

    private static function vipsAvailable()
    {
        $forced = strtolower((string)getenv('IMAGE_DRIVER'));
        if ($forced === 'gd') {
            return false;
        }

        if (!extension_loaded('ffi') || !filter_var(ini_get('ffi.enable'), FILTER_VALIDATE_BOOLEAN)
            || !class_exists(VipsDriver::class)) {
            return false;
        }

        try {
            // loads libvips through FFI, throws when the library can't be found
            \Jcupitt\Vips\Config::version();
            return true;
        } catch (\Throwable $e) {
            error_log('ImageTool: libvips not available, using GD ('.$e->getMessage().')');
            return false;
        }
    }

    /**
     * Loads an image from a file path.
     *
     * @return ImageInterface
     */
    public static function load($path)
    {
        $data = file_get_contents($path);
        if ($data === false || $data === '') {
            throw new Exception('Failed to read image: '.$path);
        }

        return self::loadData($data);
    }

    /**
     * Loads an image from its file contents (e.g. a page read from a gallery zip).
     *
     * @return ImageInterface
     */
    public static function loadData($data)
    {
        if ($data === false || $data === null || $data === '') {
            throw new Exception('Failed to read image: no data');
        }

        return self::manager()->decodeBinary($data);
    }
}
