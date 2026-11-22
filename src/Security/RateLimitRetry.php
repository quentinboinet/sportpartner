<?php

namespace App\Security;

use Symfony\Component\RateLimiter\RateLimit;

/**
 * Délai avant le prochain essai d'un limiteur refusé, pour les messages (« réessaie dans N min ») et l'en-tête
 * Retry-After. Calcul pur.
 */
final class RateLimitRetry
{
    private function __construct()
    {
    }

    /** Secondes avant le prochain essai, au moins 1. */
    public static function seconds(RateLimit $limit, ?int $now = null): int
    {
        return max(1, $limit->getRetryAfter()->getTimestamp() - ($now ?? time()));
    }

    /**
     * Minutes entières avant le prochain essai, arrondies au-dessus à 5 secondes près (le limiteur arrondit son
     * échéance à la seconde supérieure : un délai de 5 minutes s'affiche « 5 min », pas « 6 min »), au moins 1.
     */
    public static function minutes(RateLimit $limit, ?int $now = null): int
    {
        return max(1, intdiv(max(0, self::seconds($limit, $now) - 5) + 59, 60));
    }
}
