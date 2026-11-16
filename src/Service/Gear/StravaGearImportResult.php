<?php

namespace App\Service\Gear;

/**
 * Bilan d'un import du matériel Strava (StravaGearImporter).
 *
 * Une erreur (errors non vide) signifie que l'import a été interrompu et que rien n'a été enregistré.
 */
final class StravaGearImportResult
{
    /** Compte Strava non connecté (pas de jeton). */
    public const ERROR_NOT_CONNECTED = 'not_connected';
    /** 401 / 403 : jeton refusé ou autorisation insuffisante, il faut reconnecter Strava. */
    public const ERROR_AUTH = 'auth';
    /** 429 : quota de requêtes Strava atteint (fenêtre de 15 min). */
    public const ERROR_RATE_LIMIT = 'rate_limit';
    /** Réseau, délai dépassé, erreur 5xx ou réponse illisible. */
    public const ERROR_UNAVAILABLE = 'unavailable';

    /**
     * @param int          $created             Matériels créés.
     * @param int          $updated             Matériels existants mis à jour (même identifiant Strava, ou saisis à la main et reconnus).
     * @param int          $linked              Activités dont le matériel a été (re)défini.
     * @param int          $skipped             Matériels Strava ignorés (introuvables, type inconnu, réponse invalide).
     * @param list<string> $errors              Codes ERROR_* (import interrompu, rien n'est enregistré).
     * @param bool         $truncated           Trop d'activités : seules les plus récentes ont été lues.
     * @param bool         $profileScopeMissing Le jeton n'a pas l'autorisation profile:read_all : le matériel
     *                                          jamais utilisé dans une activité n'a pas pu être listé.
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $linked = 0,
        public readonly int $skipped = 0,
        public readonly array $errors = [],
        public readonly bool $truncated = false,
        public readonly bool $profileScopeMissing = false,
    ) {
    }

    public static function failed(string $error): self
    {
        return new self(errors: [$error]);
    }

    public function isSuccessful(): bool
    {
        return $this->errors === [];
    }

    /** Aucun matériel trouvé sur Strava (ni créé, ni mis à jour, ni ignoré). */
    public function isEmpty(): bool
    {
        return $this->isSuccessful() && $this->created === 0 && $this->updated === 0 && $this->skipped === 0;
    }
}
