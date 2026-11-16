<?php

namespace App\Service\User;

/** Échec de la récupération de la photo de profil Strava (StravaAvatarFetcher). */
final class StravaAvatarException extends \RuntimeException
{
    public const NOT_CONNECTED   = 1;
    /** 401 / 403, ou jeton de rafraîchissement refusé : il faut reconnecter Strava. */
    public const AUTH            = 2;
    public const RATE_LIMIT      = 3;
    /** API Strava injoignable, en erreur ou réponse illisible. */
    public const UNAVAILABLE     = 4;
    /** Profil sans photo (Strava renvoie alors un chemin relatif vers son image par défaut). */
    public const NO_PHOTO        = 5;
    /** Téléchargement de la photo impossible : erreur HTTP ou réseau, adresse privée, redirection non https. */
    public const DOWNLOAD_FAILED = 6;
    public const TOO_LARGE       = 7;

    public function translationKey(): string
    {
        return match ($this->getCode()) {
            self::NOT_CONNECTED => 'avatar.strava.not_connected',
            self::AUTH          => 'avatar.strava.auth',
            self::RATE_LIMIT    => 'avatar.strava.rate_limit',
            self::NO_PHOTO      => 'avatar.strava.no_photo',
            self::DOWNLOAD_FAILED,
            self::TOO_LARGE     => 'avatar.strava.download',
            default             => 'avatar.strava.unavailable',
        };
    }
}
