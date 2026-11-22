<?php

namespace App\Service\Route;

/**
 * Échec du téléchargement d'un GPX depuis un lien. Le code indique la cause (le contrôleur en déduit le message
 * affiché ; UNREACHABLE, HTTP_ERROR et PORT_NOT_ALLOWED partagent le même message, voir RacePlanController).
 */
final class RemoteGpxException extends \RuntimeException
{
    public const INVALID_URL      = 1;   // pas une URL http(s) valide
    public const BLOCKED          = 2;   // hôte privé / local / réservé (protection SSRF)
    public const UNREACHABLE      = 3;   // DNS, connexion, délai dépassé
    public const HTTP_ERROR       = 4;   // statut HTTP autre que 200, ou trop de redirections
    public const TOO_LARGE        = 5;   // au-delà de GpxParser::MAX_BYTES
    public const NOT_GPX          = 6;   // page web (HTML) au lieu d'un fichier
    public const PORT_NOT_ALLOWED = 7;   // port explicite autre que 80 / 443 (lien ou redirection)
}
