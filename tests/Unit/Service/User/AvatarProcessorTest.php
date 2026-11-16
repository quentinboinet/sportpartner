<?php

namespace App\Tests\Unit\Service\User;

use App\Service\User\AvatarException;
use App\Service\User\AvatarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Images générées avec GD (ou en-têtes fabriqués à la main pour les cas refusés). */
class AvatarProcessorTest extends TestCase
{
    private const RED    = [255, 0, 0];
    private const GREEN  = [0, 255, 0];
    private const BLUE   = [0, 0, 255];
    private const YELLOW = [255, 255, 0];
    private const BLACK  = [0, 0, 0];
    private const WHITE  = [255, 255, 255];

    // ── Générateurs ───────────────────────────────────────────────────────

    /** @param callable(\GdImage): void $draw */
    private static function image(int $width, int $height, callable $draw, string $format, bool $alpha = false): string
    {
        $im = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $im);
        if ($alpha) {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        }
        $draw($im);
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($im, null, 95),
            'png'  => imagepng($im),
            'webp' => imagewebp($im, null, 90),
            'gif'  => imagegif($im),
        };

        return (string) ob_get_clean();
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private static function fill(\GdImage $im, int $x1, int $y1, int $x2, int $y2, array $rgb, int $alpha = 0): void
    {
        imagefilledrectangle($im, $x1, $y1, $x2, $y2, (int) imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], $alpha));
    }

    /**
     * JPEG 640 x 320 : en dehors du carré central, noir ; dans le carré (x 160 à 480), quatre quarts
     * rouge (haut gauche), vert (haut droite), bleu (bas gauche), jaune (bas droite).
     */
    private static function quadrantJpeg(): string
    {
        return self::image(640, 320, static function (\GdImage $im): void {
            self::fill($im, 0, 0, 639, 319, self::BLACK);
            self::fill($im, 160, 0, 319, 159, self::RED);
            self::fill($im, 320, 0, 479, 159, self::GREEN);
            self::fill($im, 160, 160, 319, 319, self::BLUE);
            self::fill($im, 320, 160, 479, 319, self::YELLOW);
        }, 'jpeg');
    }

    /** Ajoute un segment APP1 Exif (orientation, description) et un commentaire après le SOI d'un JPEG. */
    private static function withExif(string $jpeg, int $orientation, string $secret = ''): string
    {
        $description = $secret."\x00";
        // TIFF petit-boutiste : en-tête (8), IFD0 à 2 entrées (2 + 2 x 12 + 4), puis la description
        $tiff = "II*\x00".pack('V', 8).pack('v', 2)
            .pack('vvVV', 0x010E, 2, strlen($description), 8 + 2 + 24 + 4)
            .pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
            .pack('V', 0)
            .$description;
        $app1    = "Exif\x00\x00".$tiff;
        $comment = $secret !== '' ? "\xFF\xFE".pack('n', strlen($secret) + 2).$secret : '';

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.$comment.substr($jpeg, 2);
    }

    /** En-tête PNG (signature + IHDR) annonçant des dimensions, sans données d'image. */
    private static function pngHeader(int $width, int $height, int $bitDepth = 8, int $colorType = 6): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, $bitDepth, $colorType, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr))
            .pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    }

    private static function decode(string $data): \GdImage
    {
        $im = imagecreatefromstring($data);
        self::assertInstanceOf(\GdImage::class, $im);

        return $im;
    }

    /** Couleur de référence la plus proche du pixel (tolère les artefacts JPEG / WebP). */
    private static function colorAt(\GdImage $im, int $x, int $y): string
    {
        $c   = imagecolorat($im, $x, $y);
        $rgb = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
        $best = '';
        $bestDistance = PHP_INT_MAX;
        foreach (['R' => self::RED, 'G' => self::GREEN, 'B' => self::BLUE, 'Y' => self::YELLOW, 'K' => self::BLACK, 'W' => self::WHITE] as $name => $ref) {
            $distance = ($rgb[0] - $ref[0]) ** 2 + ($rgb[1] - $ref[1]) ** 2 + ($rgb[2] - $ref[2]) ** 2;
            if ($distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private static function alphaAt(\GdImage $im, int $x, int $y): int
    {
        return (imagecolorat($im, $x, $y) >> 24) & 0x7F;
    }

    private function assertRejected(int $code, string $bytes, ?AvatarProcessor $processor = null): void
    {
        try {
            ($processor ?? new AvatarProcessor())->process($bytes);
            $this->fail('AvatarException expected');
        } catch (AvatarException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
            $this->assertStringStartsWith('avatar.error.', $e->translationKey());
        }
    }

    // ── Images acceptées ──────────────────────────────────────────────────

    /** @return iterable<string, array{0: int, 1: list<string>}> [orientation EXIF, couleurs haut gauche, haut droite, bas gauche, bas droite] */
    public static function orientations(): iterable
    {
        yield '1 normal' => [1, ['R', 'G', 'B', 'Y']];
        yield '2 miroir horizontal' => [2, ['G', 'R', 'Y', 'B']];
        yield '3 demi-tour' => [3, ['Y', 'B', 'G', 'R']];
        yield '4 miroir vertical' => [4, ['B', 'Y', 'R', 'G']];
        yield '5 transposition' => [5, ['R', 'B', 'G', 'Y']];
        yield '6 quart de tour horaire' => [6, ['B', 'R', 'Y', 'G']];
        yield '7 transversale' => [7, ['Y', 'G', 'B', 'R']];
        yield '8 quart de tour antihoraire' => [8, ['G', 'Y', 'R', 'B']];
    }

    /** @param list<string> $expected */
    #[DataProvider('orientations')]
    public function testJpegIsCroppedResizedAndOriented(int $orientation, array $expected): void
    {
        if ($orientation !== 1 && !function_exists('exif_read_data')) {
            $this->markTestSkipped('exif extension not available');
        }

        $avatar = (new AvatarProcessor())->process(self::withExif(self::quadrantJpeg(), $orientation));

        $this->assertSame('image/webp', $avatar->mimeType);
        $this->assertSame([256, 256], [$avatar->width, $avatar->height]);
        $out = self::decode($avatar->data);
        $this->assertSame([256, 256], [imagesx($out), imagesy($out)]);
        // Le noir hors du carré central a été recadré ; quarts attendus après orientation
        $this->assertSame($expected, [self::colorAt($out, 64, 64), self::colorAt($out, 192, 64), self::colorAt($out, 64, 192), self::colorAt($out, 192, 192)]);
        foreach ([[2, 2], [253, 2], [2, 253], [253, 253]] as [$x, $y]) {
            $this->assertNotSame('K', self::colorAt($out, $x, $y), "corner $x,$y");
        }
    }

    public function testPortraitIsCenterCropped(): void
    {
        $png = self::image(300, 900, static function (\GdImage $im): void {
            self::fill($im, 0, 0, 299, 299, self::RED);
            self::fill($im, 0, 300, 299, 599, self::GREEN);
            self::fill($im, 0, 600, 299, 899, self::BLUE);
        }, 'png');

        $out = self::decode((new AvatarProcessor())->process($png)->data);

        foreach ([[3, 3], [128, 128], [252, 252], [3, 252]] as [$x, $y]) {
            $this->assertSame('G', self::colorAt($out, $x, $y), "pixel $x,$y");
        }
    }

    public function testMetadataIsStripped(): void
    {
        $jpeg = self::withExif(self::quadrantJpeg(), 1, 'SECRET-GPS-48.8566N');
        $this->assertStringContainsString('SECRET-GPS', $jpeg);

        $avatar = (new AvatarProcessor())->process($jpeg);

        $this->assertStringStartsWith('RIFF', $avatar->data);
        $this->assertSame('WEBP', substr($avatar->data, 8, 4));
        $this->assertStringNotContainsString('SECRET-GPS', $avatar->data);
        $this->assertStringNotContainsString('Exif', $avatar->data);
        $this->assertStringNotContainsString('EXIF', $avatar->data);
    }

    public function testPngTransparencyIsKeptInWebp(): void
    {
        $png = self::image(300, 300, static function (\GdImage $im): void {
            self::fill($im, 100, 100, 199, 199, self::GREEN);
        }, 'png', true);

        $avatar = (new AvatarProcessor())->process($png);
        $out    = self::decode($avatar->data);

        $this->assertSame('image/webp', $avatar->mimeType);
        $this->assertSame(127, self::alphaAt($out, 5, 5), 'transparent corner');
        $this->assertSame(0, self::alphaAt($out, 128, 128), 'opaque center');
        $this->assertSame('G', self::colorAt($out, 128, 128));
    }

    public function testJpegFallbackFlattensTransparencyOnWhite(): void
    {
        $png = self::image(300, 300, static function (\GdImage $im): void {
            self::fill($im, 100, 100, 199, 199, self::GREEN);
        }, 'png', true);

        $avatar = (new AvatarProcessor(webp: false))->process($png);
        $out    = self::decode($avatar->data);

        $this->assertSame('image/jpeg', $avatar->mimeType);
        $this->assertSame("\xFF\xD8", substr($avatar->data, 0, 2));
        $this->assertSame([256, 256], [imagesx($out), imagesy($out)]);
        $this->assertSame('W', self::colorAt($out, 5, 5));
        $this->assertSame('G', self::colorAt($out, 128, 128));
    }

    public function testWebpInput(): void
    {
        $webp = self::image(400, 300, static function (\GdImage $im): void {
            self::fill($im, 0, 0, 399, 299, self::BLUE);
        }, 'webp');

        $avatar = (new AvatarProcessor())->process($webp);

        $this->assertSame([256, 256], [$avatar->width, $avatar->height]);
        $this->assertSame('B', self::colorAt(self::decode($avatar->data), 128, 128));
    }

    public function testTinyGifKeepsItsSizeAndTransparency(): void
    {
        $im = imagecreate(16, 16);
        $this->assertInstanceOf(\GdImage::class, $im);
        $transparent = (int) imagecolorallocate($im, 255, 0, 255);
        imagecolortransparent($im, $transparent);
        imagefilledrectangle($im, 8, 0, 15, 15, (int) imagecolorallocate($im, 255, 0, 0));
        ob_start();
        imagegif($im);
        $gif = (string) ob_get_clean();

        $avatar = (new AvatarProcessor())->process($gif);
        $out    = self::decode($avatar->data);

        // Jamais agrandie : une image plus petite que 256 px garde sa taille
        $this->assertSame([16, 16], [$avatar->width, $avatar->height]);
        $this->assertSame(127, self::alphaAt($out, 2, 8));
        $this->assertSame('R', self::colorAt($out, 13, 8));
        $this->assertSame(0, self::alphaAt($out, 13, 8));
    }

    // ── Images refusées ───────────────────────────────────────────────────

    public function testEmptyFile(): void
    {
        $this->assertRejected(AvatarException::EMPTY, '');
    }

    public function testTooLarge(): void
    {
        $this->assertRejected(AvatarException::TOO_LARGE, "\xFF\xD8\xFF".str_repeat("\x00", AvatarProcessor::MAX_BYTES));
    }

    public function testTextFileRenamedAsJpeg(): void
    {
        $this->assertRejected(AvatarException::UNSUPPORTED_TYPE, "Ceci n'est pas une image.\nphoto.jpg\n");
    }

    public function testSvgIsRefused(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="300" height="300">'
            .'<script>alert(1)</script><rect width="300" height="300" fill="red"/></svg>';
        $this->assertRejected(AvatarException::UNSUPPORTED_TYPE, $svg);
    }

    public function testHeicIsRefusedWithItsOwnMessage(): void
    {
        $heic = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64);
        $this->assertRejected(AvatarException::HEIC, $heic);
        $this->assertSame('avatar.error.heic', (new AvatarException('', AvatarException::HEIC))->translationKey());
    }

    public function testCorruptJpegHeader(): void
    {
        // Signature et segment JFIF valides, puis plus aucun marqueur (pas de dimensions)
        $this->assertRejected(AvatarException::INVALID_IMAGE, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat('garbage ', 40));
    }

    public function testOversizedDimensionsAreRefusedBeforeDecoding(): void
    {
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        // 10 000 x 10 000 = 100 mégapixels annoncés par l'en-tête
        $this->assertRejected(AvatarException::TOO_MANY_PIXELS, self::pngHeader(10_000, 10_000));
        // GIF de 65 535 x 65 535 : en-tête seul
        $this->assertRejected(AvatarException::TOO_MANY_PIXELS, "GIF89a\xFF\xFF\xFF\xFF\x00\x00\x00;");

        $this->assertLessThan(5 * 1024 * 1024, memory_get_peak_usage() - $before, 'nothing decoded');
    }

    public function testDecodeMemoryCapRefusesHugePngUnderPixelLimit(): void
    {
        // 6 000 x 6 000 = 36 mégapixels (< 40) mais un PNG RGBA 16 bits demande ~13 octets par pixel
        $this->assertRejected(AvatarException::TOO_MANY_PIXELS, self::pngHeader(6_000, 6_000, 16));
    }

    public function testAnimatedGifIsRefused(): void
    {
        $im = imagecreate(20, 20);
        $this->assertInstanceOf(\GdImage::class, $im);
        imagecolorallocate($im, 0, 128, 255);
        ob_start();
        imagegif($im);
        $gif = (string) ob_get_clean();
        // Une image GIF : en-tête + palette globale, descripteur d'image (","), données, fin (";")
        $flags = ord($gif[10]);
        $start = 13 + (($flags & 0x80) !== 0 ? 3 * (2 << ($flags & 0x07)) : 0);
        $this->assertSame(',', $gif[$start]);
        $frame    = substr($gif, $start, -1);
        $animated = substr($gif, 0, $start)."\x21\xF9\x04\x00\x0A\x00\x00\x00".$frame."\x21\xF9\x04\x00\x0A\x00\x00\x00".$frame.';';

        $this->assertNotFalse(@imagecreatefromstring($animated), 'GD would only read the first frame');
        $this->assertRejected(AvatarException::ANIMATED, $animated);
        // L'image fixe d'origine est acceptée
        $still = (new AvatarProcessor())->process($gif);
        $this->assertSame([20, 20], [$still->width, $still->height]);
    }

    public function testAnimatedPngIsRefused(): void
    {
        $png = self::image(20, 20, static function (\GdImage $im): void {
            self::fill($im, 0, 0, 19, 19, self::GREEN);
        }, 'png');
        // Bloc acTL (2 images, lecture infinie) inséré après IHDR (signature 8 + IHDR 25 octets)
        $actl = pack('NN', 2, 0);
        $apng = substr($png, 0, 33).pack('N', 8).'acTL'.$actl.pack('N', crc32('acTL'.$actl)).substr($png, 33);
        $this->assertSame('image/png', (new \finfo(\FILEINFO_MIME_TYPE))->buffer($apng));

        $this->assertRejected(AvatarException::ANIMATED, $apng);
        $this->assertSame(20, (new AvatarProcessor())->process($png)->width, 'still PNG accepted');
    }

    public function testAnimatedWebpIsRefused(): void
    {
        // WebP étendu (VP8X) avec le drapeau animation, canevas 100 x 100, puis un bloc ANIM
        $vp8x  = 'VP8X'.pack('V', 10).chr(0x02)."\x00\x00\x00".substr(pack('V', 99), 0, 3).substr(pack('V', 99), 0, 3);
        $anim  = 'ANIM'.pack('V', 6).pack('V', 0).pack('v', 0);
        $webp  = 'RIFF'.pack('V', 4 + strlen($vp8x) + strlen($anim)).'WEBP'.$vp8x.$anim;

        $this->assertRejected(AvatarException::ANIMATED, $webp);
    }

    public function testUploadLimitIsNeverAboveFiveMegabytes(): void
    {
        $this->assertGreaterThan(0, AvatarProcessor::maxUploadBytes());
        $this->assertLessThanOrEqual(AvatarProcessor::MAX_BYTES, AvatarProcessor::maxUploadBytes());
        $this->assertGreaterThanOrEqual(1, AvatarProcessor::maxUploadMegabytes());
        $this->assertLessThanOrEqual(5, AvatarProcessor::maxUploadMegabytes());
    }
}
