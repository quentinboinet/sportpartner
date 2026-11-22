<?php

namespace App\Service\Strava;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Paramètre `state` de l'OAuth Strava (protection CSRF du retour /oauth/strava/callback).
 *
 * issue() tire 128 bits aléatoires et les garde en session avec l'heure ; consume() les RETIRE de la session quel
 * que soit le résultat (usage unique) puis compare en temps constant (hash_equals). Refusé : valeur absente, autre
 * qu'une chaîne, différente, déjà utilisée (plus en session) ou plus vieille que TTL_SECONDS. Un nouvel issue()
 * remplace le précédent (un seul aller-retour vers Strava à la fois).
 */
final class StravaOAuthState
{
    public const SESSION_KEY = '_strava_oauth_state';
    /** Durée de vie d'un aller-retour vers l'écran d'autorisation Strava. */
    public const TTL_SECONDS = 600;

    public function issue(SessionInterface $session, ?int $now = null): string
    {
        $state = bin2hex(random_bytes(16));
        $session->set(self::SESSION_KEY, ['value' => $state, 'at' => $now ?? time()]);

        return $state;
    }

    public function consume(SessionInterface $session, mixed $received, ?int $now = null): bool
    {
        $stored = $session->remove(self::SESSION_KEY);
        if (!\is_array($stored) || !\is_string($stored['value'] ?? null) || !\is_int($stored['at'] ?? null)) {
            return false;
        }
        if (!\is_string($received) || '' === $received || ($now ?? time()) - $stored['at'] > self::TTL_SECONDS) {
            return false;
        }

        return hash_equals($stored['value'], $received);
    }
}
