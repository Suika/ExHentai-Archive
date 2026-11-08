<?php

namespace Tests\Unit;

use Intervention\Image\Encoders\JpegEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ZipFactory;

/**
 * Image loading/resizing (ImageTool), with both engines: libvips and the GD fallback.
 */
final class ImageToolTest extends TestCase
{
    private static function useDriver(?string $driver): void
    {
        putenv($driver === null ? 'IMAGE_DRIVER' : 'IMAGE_DRIVER='.$driver);
        foreach (array('manager', 'driverName') as $name) {
            (new \ReflectionProperty(\ImageTool::class, $name))->setValue(null, null);
        }
    }

    protected function tearDown(): void
    {
        self::useDriver(null);
    }

    public static function drivers(): array
    {
        return array('vips' => array('vips'), 'gd' => array('gd'));
    }

    #[DataProvider('drivers')]
    public function testDriverSelection(string $driver): void
    {
        self::useDriver($driver);
        $this->assertSame($driver, \ImageTool::driverName());
    }

    #[DataProvider('drivers')]
    public function testDecodesAllPageFormats(string $driver): void
    {
        self::useDriver($driver);

        foreach (array('jpeg', 'png', 'gif', 'webp') as $i => $format) {
            $image = \ImageTool::loadData(ZipFactory::image($format, 300 + $i, 200 + $i));
            $this->assertSame(array(300 + $i, 200 + $i), array($image->width(), $image->height()), $format);
        }
    }

    #[DataProvider('drivers')]
    public function testScaleKeepsAspectRatio(string $driver): void
    {
        self::useDriver($driver);

        $image = \ImageTool::loadData(ZipFactory::image('jpeg', 800, 1200));
        $image->scale(width: 350);

        $this->assertSame(array(350, 525), array($image->width(), $image->height()));

        $jpeg = $image->encode(new JpegEncoder(quality: 95))->toString();
        $info = getimagesizefromstring($jpeg);
        $this->assertSame(array(350, 525, IMAGETYPE_JPEG), array($info[0], $info[1], $info[2]));
    }

    #[DataProvider('drivers')]
    public function testScaleUpscalesSmallImages(string $driver): void
    {
        self::useDriver($driver);

        // the API clamps "resize" to the original width itself (ApiHandler::archiveimageAction)
        $image = \ImageTool::loadData(ZipFactory::image('png', 100, 150));
        $image->scale(width: 200);

        $this->assertSame(array(200, 300), array($image->width(), $image->height()));
    }

    #[DataProvider('drivers')]
    public function testExifOrientationIsIgnored(string $driver): void
    {
        self::useDriver($driver);

        // portrait JPEG tagged "rotate 90°": pages are shown as stored, like the old PHPImageWorkshop did
        $image = \ImageTool::loadData(self::withExifOrientation(ZipFactory::image('jpeg', 800, 1200), 6));

        $this->assertSame(array(800, 1200), array($image->width(), $image->height()));
    }

    public function testEmptyDataThrows(): void
    {
        $this->expectException(\Exception::class);
        \ImageTool::loadData('');
    }

    private static function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "II*\0".pack('V', 8)                    // little endian TIFF header, IFD at offset 8
            .pack('v', 1)                                 // one entry
            .pack('vvVvv', 0x0112, 3, 1, $orientation, 0) // Orientation, SHORT, count 1, value
            .pack('V', 0);                                // no next IFD
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }
}
