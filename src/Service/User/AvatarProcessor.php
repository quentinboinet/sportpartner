<?php

namespace App\Service\User;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Retraite une photo de profil (envoyée par l'utilisateur ou récupérée sur Strava).
 *
 * 1. Contrôles avant tout décodage : 5 Mo au plus ; type réel d'après le contenu (finfo, jamais
 *    l'extension ni le type annoncé par le navigateur) confirmé par getimagesize : JPEG, PNG, WebP
 *    et GIF fixe seulement (HEIC, SVG, AVIF, BMP, GIF, PNG ou WebP animés refusés) ; 40 mégapixels au
 *    plus et mémoire suffisante pour le décodage (memory_limit relevé si besoin, 256 Mo au plus
 *    pour l'image décodée).
 * 2. Décodage GD, recadrage carré centré, réduction à 256 x 256 (une image plus petite n'est pas
 *    agrandie), puis orientation EXIF des JPEG (si l'extension exif est présente).
 * 3. Réencodage en WebP qualité 85 en gardant la transparence, ou en JPEG sur fond blanc si GD
 *    ne sait pas écrire le WebP. Le réencodage supprime toutes les métadonnées (EXIF, GPS...).
 */
final class AvatarProcessor
{
    public const MAX_BYTES  = 5 * 1024 * 1024;
    public const MAX_PIXELS = 40_000_000;
    public const SIZE       = 256;

    private const WEBP_QUALITY = 85;
    private const JPEG_QUALITY = 88;

    /** Mémoire maximale pour décoder l'image source (estimation de decodeMemory()). */
    private const MAX_DECODE_MEMORY = 256 * 1024 * 1024;
    private const MEMORY_MARGIN     = 8 * 1024 * 1024;

    /** Types acceptés : type MIME détecté d'après le contenu => type attendu de getimagesize. */
    private const TYPES = [
        'image/jpeg' => \IMAGETYPE_JPEG,
        'image/png'  => \IMAGETYPE_PNG,
        'image/webp' => \IMAGETYPE_WEBP,
        'image/gif'  => \IMAGETYPE_GIF,
    ];

    private const HEIF_TYPES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

    /**
     * @param bool|null $webp Format de sortie : null = WebP si GD sait l'écrire, sinon JPEG ; false force le JPEG.
     */
    public function __construct(private readonly ?bool $webp = null)
    {
    }

    /**
     * Taille maximale d'un envoi : MAX_BYTES, ou moins si upload_max_filesize ou post_max_size
     * sont plus bas sur ce serveur.
     */
    public static function maxUploadBytes(): int
    {
        return (int) min(self::MAX_BYTES, UploadedFile::getMaxFilesize());
    }

    /** maxUploadBytes() en Mo entiers (arrondi inférieur, 1 au moins), pour les messages. */
    public static function maxUploadMegabytes(): int
    {
        return max(1, intdiv(self::maxUploadBytes(), 1024 * 1024));
    }

    /**
     * @throws AvatarException
     */
    public function process(string $bytes): ProcessedAvatar
    {
        [$type, $width, $height, $bits] = self::inspect($bytes);

        self::reserveMemory(self::decodeMemory($type, $width * $height, $bits));
        $source = @imagecreatefromstring($bytes);
        if (!$source instanceof \GdImage) {
            throw new AvatarException('The image could not be decoded.', AvatarException::INVALID_IMAGE);
        }

        $orientation = $type === \IMAGETYPE_JPEG ? self::jpegOrientation($bytes) : 1;
        $square = self::cropAndResize($source);
        unset($source);

        // Le carré centré d'une image tournée est le carré centré tourné : on oriente après réduction (moins de mémoire)
        return $this->encode(self::orient($square, $orientation));
    }

    /**
     * Contrôles sans décodage.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [type IMAGETYPE_*, largeur, hauteur, bits par canal]
     *
     * @throws AvatarException
     */
    private static function inspect(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length === 0) {
            throw new AvatarException('Empty file.', AvatarException::EMPTY);
        }
        if ($length > self::MAX_BYTES) {
            throw new AvatarException('File too large.', AvatarException::TOO_LARGE);
        }

        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes);
        $mime = is_string($mime) ? $mime : '';
        if (in_array($mime, self::HEIF_TYPES, true)) {
            throw new AvatarException('HEIC / HEIF images are not supported.', AvatarException::HEIC);
        }
        $type = self::TYPES[$mime] ?? null;
        if ($type === null) {
            throw new AvatarException(sprintf('Unsupported file type "%s".', $mime), AvatarException::UNSUPPORTED_TYPE);
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== $type || $info[0] < 1 || $info[1] < 1) {
            throw new AvatarException('Invalid image header.', AvatarException::INVALID_IMAGE);
        }
        [$width, $height] = $info;
        if ($width * $height > self::MAX_PIXELS) {
            throw new AvatarException(sprintf('Image too large (%d x %d).', $width, $height), AvatarException::TOO_MANY_PIXELS);
        }
        if (self::isAnimated($type, $bytes)) {
            throw new AvatarException('Animated images are not supported.', AvatarException::ANIMATED);
        }

        return [$type, $width, $height, (int) ($info['bits'] ?? 8)];
    }

    /**
     * Mémoire nécessaire au décodage, estimation prudente. Mesuré avec GD : image en couleurs vraies
     * 4 octets par pixel ; un PNG est d'abord décodé en entier dans un tampon (jusqu'à 4 canaux) ;
     * un GIF est décodé en palette (1 octet par pixel) puis converti en couleurs vraies.
     */
    private static function decodeMemory(int $type, int $pixels, int $bits): int
    {
        $perPixel = match ($type) {
            \IMAGETYPE_PNG => 5 + 4 * max(1, intdiv($bits + 7, 8)),
            \IMAGETYPE_GIF => 6,
            default        => 5,
        };

        return $pixels * $perPixel + self::MEMORY_MARGIN;
    }

    /**
     * Vérifie que le décodage tient en mémoire, en relevant memory_limit si besoin : une image trop
     * grande est refusée proprement au lieu de provoquer une erreur fatale.
     *
     * @throws AvatarException
     */
    private static function reserveMemory(int $needed): void
    {
        if ($needed > self::MAX_DECODE_MEMORY) {
            throw new AvatarException('Image too large to decode.', AvatarException::TOO_MANY_PIXELS);
        }

        $limit = @ini_parse_quantity((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return; // -1 : pas de limite
        }
        $usage = memory_get_usage();
        if ($limit - $usage >= $needed) {
            return;
        }
        if (@ini_set('memory_limit', (string) ($usage + $needed)) === false) {
            throw new AvatarException('Not enough memory to decode the image.', AvatarException::TOO_MANY_PIXELS);
        }
    }

    private static function isAnimated(int $type, string $bytes): bool
    {
        return match ($type) {
            \IMAGETYPE_GIF  => self::gifFrameCount($bytes) > 1,
            \IMAGETYPE_PNG  => self::isAnimatedPng($bytes),
            // WebP étendu (en-tête VP8X) : bit "animation" des drapeaux
            \IMAGETYPE_WEBP => substr($bytes, 12, 4) === 'VP8X' && strlen($bytes) > 20 && (ord($bytes[20]) & 0x02) !== 0,
            default         => false,
        };
    }

    /** PNG animé (APNG, que finfo donne pour "image/png") : bloc acTL avant les données d'image (IDAT). */
    private static function isAnimatedPng(string $png): bool
    {
        $length = strlen($png);
        $pos    = 8; // après la signature
        while ($pos + 8 <= $length) {
            $header = unpack('Nsize/a4type', substr($png, $pos, 8));
            if ($header === false || $header['type'] === 'IDAT') {
                return false;
            }
            if ($header['type'] === 'acTL') {
                return true;
            }
            $pos += 12 + $header['size']; // longueur, type, données, CRC
        }

        return false;
    }

    /** Nombre d'images d'un GIF, en parcourant ses blocs (arrêt à 2 : au-delà d'une, il est animé). */
    private static function gifFrameCount(string $gif): int
    {
        $length = strlen($gif);
        if ($length < 13) {
            return 0;
        }
        $flags  = ord($gif[10]);
        $pos    = 13 + (($flags & 0x80) !== 0 ? 3 * (2 << ($flags & 0x07)) : 0); // + palette globale
        $frames = 0;
        while ($pos < $length && $frames < 2) {
            $block = ord($gif[$pos]);
            if ($block === 0x21) {
                // Extension : introducteur, étiquette, sous-blocs
                $pos = self::skipGifSubBlocks($gif, $pos + 2);
            } elseif ($block === 0x2C && $pos + 10 <= $length) {
                // Image : descripteur (10 octets), palette locale, taille de code LZW, sous-blocs
                $frames++;
                $local = ord($gif[$pos + 9]);
                $pos  += 10 + (($local & 0x80) !== 0 ? 3 * (2 << ($local & 0x07)) : 0);
                $pos   = self::skipGifSubBlocks($gif, $pos + 1);
            } else {
                break; // fin du fichier (0x3B) ou données invalides
            }
        }

        return $frames;
    }

    private static function skipGifSubBlocks(string $gif, int $pos): int
    {
        $length = strlen($gif);
        while ($pos < $length) {
            $size = ord($gif[$pos]);
            $pos += 1 + $size;
            if ($size === 0) {
                break;
            }
        }

        return $pos;
    }

    /** Orientation EXIF d'un JPEG, de 1 à 8 (1 si absente, illisible ou sans l'extension exif). */
    private static function jpegOrientation(string $jpeg): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return 1;
        }
        fwrite($stream, $jpeg);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Carré centré, réduit à SIZE pixels de côté au plus, transparence conservée.
     *
     * @throws AvatarException
     */
    private static function cropAndResize(\GdImage $source): \GdImage
    {
        if (!imageistruecolor($source)) {
            imagepalettetotruecolor($source); // la couleur transparente de la palette devient transparente (alpha)
        }
        $width  = imagesx($source);
        $height = imagesy($source);
        $side   = min($width, $height);
        $size   = min(self::SIZE, $side);

        $square = imagecreatetruecolor($size, $size);
        if ($square === false) {
            throw new AvatarException('Could not create the image.', AvatarException::PROCESSING_FAILED);
        }
        imagealphablending($square, false);
        imagesavealpha($square, true);
        if (!imagecopyresampled($square, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $size, $size, $side, $side)) {
            throw new AvatarException('Could not resize the image.', AvatarException::PROCESSING_FAILED);
        }

        return $square;
    }

    /**
     * Applique l'orientation EXIF (imagerotate tourne dans le sens antihoraire).
     *
     * @throws AvatarException
     */
    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        if ($orientation === 2 || $orientation === 4) {
            imageflip($image, $orientation === 2 ? \IMG_FLIP_HORIZONTAL : \IMG_FLIP_VERTICAL);

            return $image;
        }

        $angle = match ($orientation) {
            3       => 180,
            5, 6, 7 => 270, // quart de tour horaire (5 et 7 : puis miroir)
            8       => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated === false) {
                throw new AvatarException('Could not rotate the image.', AvatarException::PROCESSING_FAILED);
            }
            imagealphablending($rotated, false);
            imagesavealpha($rotated, true);
            $image = $rotated;
        }
        if ($orientation === 5) {
            imageflip($image, \IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 7) {
            imageflip($image, \IMG_FLIP_VERTICAL);
        }

        return $image;
    }

    /**
     * @throws AvatarException
     */
    private function encode(\GdImage $image): ProcessedAvatar
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $webp   = $this->webp ?? (function_exists('imagewebp') && (imagetypes() & \IMG_WEBP) !== 0);

        if (!$webp) {
            // JPEG : pas de transparence, l'image est posée sur un fond blanc
            $flat = imagecreatetruecolor($width, $height);
            if ($flat === false) {
                throw new AvatarException('Could not create the image.', AvatarException::PROCESSING_FAILED);
            }
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagealphablending($flat, true);
            imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
            $image = $flat;
        }

        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new AvatarException('Could not encode the image.', AvatarException::PROCESSING_FAILED);
        }
        $written = $webp ? imagewebp($image, $stream, self::WEBP_QUALITY) : imagejpeg($image, $stream, self::JPEG_QUALITY);
        rewind($stream);
        $data = stream_get_contents($stream);
        fclose($stream);
        if (!$written || !is_string($data) || $data === '') {
            throw new AvatarException('Could not encode the image.', AvatarException::PROCESSING_FAILED);
        }

        return new ProcessedAvatar($data, $webp ? 'image/webp' : 'image/jpeg', $width, $height);
    }
}
