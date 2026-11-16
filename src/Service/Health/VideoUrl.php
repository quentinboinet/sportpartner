<?php

namespace App\Service\Health;

/**
 * Lien vidéo d'un exercice (exercice personnel, ou lien personnel sur un exercice fourni) : adresse http(s)
 * absolue, avec un nom d'hôte, sans identifiants, sans espace ni caractère de contrôle, 500 caractères au plus.
 * Tout autre schéma (javascript:, data:, file:...) est refusé. Le lien est affiché comme un simple lien
 * (target="_blank" rel="noopener noreferrer"), jamais intégré dans la page.
 */
final class VideoUrl
{
    public const ERROR_INVALID = 'health.form.error.video_invalid';
    public const ERROR_TOO_LONG = 'health.form.error.video_too_long';

    /**
     * Adresse normalisée (espaces de début et de fin retirés), ou null si la saisie est vide.
     *
     * @throws \InvalidArgumentException message = clé de traduction de l'erreur (ERROR_*)
     */
    public static function normalize(string $raw): ?string
    {
        $url = trim($raw);
        if ($url === '') {
            return null;
        }
        if (mb_strlen($url) > HealthLimits::VIDEO_URL_MAX) {
            throw new \InvalidArgumentException(self::ERROR_TOO_LONG);
        }
        if (preg_match('/[\s\x00-\x1F\x7F]/u', $url) === 1 || preg_match('#^https?://#i', $url) !== 1) {
            throw new \InvalidArgumentException(self::ERROR_INVALID);
        }
        $parts = parse_url($url);
        if ($parts === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/^[\p{L}\p{N}.\-]+$/u', $parts['host']) !== 1) {
            throw new \InvalidArgumentException(self::ERROR_INVALID);
        }

        return $url;
    }
}
