<?php

namespace App\Service\Notifications;

use App\Entity\User;

/**
 * Connexion Strava telle qu'elle est enregistrée dans User (aucun appel à Strava).
 *
 * Seule panne détectable sans appel : compte connecté sans jeton de rafraîchissement et jeton d'accès absent
 * ou expiré. StravaService::refreshAccessToken() ne peut alors rien faire et la synchro échoue. Les
 * autorisations accordées (scope) ne sont pas enregistrées : un compte connecté avant l'ajout de
 * profile:read_all n'est pas détectable ici.
 */
final class StravaState
{
    public function __construct(
        public readonly bool $connected,
        public readonly bool $hasAccessToken = true,
        public readonly bool $hasRefreshToken = true,
        public readonly ?\DateTimeImmutable $tokenExpiresAt = null,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self(
            connected: $user->isStravaConnected(),
            hasAccessToken: (string) $user->getStravaAccessToken() !== '',
            hasRefreshToken: (string) $user->getStravaRefreshToken() !== '',
            tokenExpiresAt: $user->getStravaTokenExpiresAt(),
        );
    }

    public function needsReconnect(\DateTimeImmutable $now): bool
    {
        if (!$this->connected || $this->hasRefreshToken) {
            return false;
        }

        return !$this->hasAccessToken || $this->tokenExpiresAt === null || $this->tokenExpiresAt <= $now;
    }
}
