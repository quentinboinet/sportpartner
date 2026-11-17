<?php

namespace App\Service\Activity;

use App\Entity\Activity;

/**
 * Origine d'une activité, déduite des champs existants (pas de colonne dédiée) :
 *  - Strava : stravaId renseigné (importée par la synchro, en lecture seule hors matériel et ravitaillement) ;
 *  - Santé : pas de stravaId et une séance Santé pointe vers elle (healthSession.activity_id, unique), gérée
 *    depuis l'historique Santé ;
 *  - Saisie manuelle : ni l'un ni l'autre. Seules celles-ci se modifient et se suppriment depuis les activités.
 *
 * Une activité dont la séance Santé a été supprimée en la gardant (healthSession.activity_id passe à NULL)
 * devient une activité locale ordinaire, donc « Saisie manuelle ».
 */
enum ActivitySource: string
{
    case Strava = 'strava';
    case Manual = 'manual';
    case Health = 'health';

    public static function of(?int $stravaId, bool $fromHealthSession): self
    {
        return match (true) {
            $stravaId !== null => self::Strava,
            $fromHealthSession => self::Health,
            default            => self::Manual,
        };
    }

    public static function forActivity(Activity $activity, bool $fromHealthSession): self
    {
        return self::of($activity->getStravaId(), $fromHealthSession);
    }

    /** Clé de traduction du libellé (activity_source.*). */
    public function labelKey(): string
    {
        return 'activity_source.'.$this->value;
    }

    /** Classe Bootstrap Icons du badge. */
    public function icon(): string
    {
        return match ($this) {
            self::Strava => 'bi-arrow-repeat',
            self::Manual => 'bi-pencil-square',
            self::Health => 'bi-heart-pulse',
        };
    }

    /** Modifiable et supprimable depuis les activités (saisie manuelle uniquement). */
    public function isEditable(): bool
    {
        return $this === self::Manual;
    }
}
