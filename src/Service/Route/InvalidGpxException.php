<?php

namespace App\Service\Route;

/**
 * Fichier GPX vide, mal formé, dangereux (DOCTYPE/entités) ou sans tracé exploitable.
 * Code TOO_LARGE : fichier ou nombre de points au-delà des limites.
 */
final class InvalidGpxException extends \RuntimeException
{
    public const TOO_LARGE = 1;
}
