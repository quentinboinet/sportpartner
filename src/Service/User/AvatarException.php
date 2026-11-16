<?php

namespace App\Service\User;

/**
 * Image refusée ou impossible à traiter par AvatarProcessor. Le code identifie la cause,
 * translationKey() donne le message à afficher.
 */
final class AvatarException extends \RuntimeException
{
    public const EMPTY             = 1;
    public const TOO_LARGE         = 2;
    public const UNSUPPORTED_TYPE  = 3;
    public const HEIC              = 4;
    public const ANIMATED          = 5;
    public const TOO_MANY_PIXELS   = 6;
    public const INVALID_IMAGE     = 7;
    public const PROCESSING_FAILED = 8;

    public function translationKey(): string
    {
        return match ($this->getCode()) {
            self::EMPTY            => 'avatar.error.empty',
            self::TOO_LARGE        => 'avatar.error.too_large',
            self::UNSUPPORTED_TYPE => 'avatar.error.type',
            self::HEIC             => 'avatar.error.heic',
            self::ANIMATED         => 'avatar.error.animated',
            self::TOO_MANY_PIXELS  => 'avatar.error.dimensions',
            self::INVALID_IMAGE    => 'avatar.error.invalid',
            default                => 'avatar.error.processing',
        };
    }
}
