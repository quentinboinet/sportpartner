<?php

namespace App\Service\Health;

/**
 * Bornes de saisie de l'espace Santé (exercices personnels, plateau de séance, séance enregistrée).
 * Les valeurs utiles au navigateur sont passées à health_session_controller.js par la page (jamais recopiées).
 * Bornes de la valeur d'une série (répétitions / secondes) : HealthDosageMode::minValue() / maxValue().
 */
final class HealthLimits
{
    public const SETS_MIN = 1;
    public const SETS_MAX = 10;
    public const REST_MIN = 0;
    public const REST_MAX = 600;

    /** Pause de changement de côté d'un exercice unilatéral (« chaque côté »), en secondes. */
    public const SIDE_SWITCH_SECONDS = 5;
    /** Compte à rebours « Prépare-toi » avant le premier exercice, en secondes. */
    public const PREP_SECONDS = 5;

    public const SESSION_EXERCISES_MAX = 30;
    public const SESSION_NAME_MAX = 80;
    public const NOTES_MAX = 1000;
    public const RPE_MIN = 1;
    public const RPE_MAX = 10;
    /** Durée active maximale d'une séance : 6 h. */
    public const DURATION_MAX = 21600;
    /** Durée écoulée maximale (pauses et rechargements compris) : 24 h. */
    public const ELAPSED_MAX = 86400;
    /** Une séance peut être enregistrée jusqu'à 48 h après son début. */
    public const START_MAX_AGE = 172800;
    /** Décalage d'horloge toléré entre le navigateur et le serveur. */
    public const START_FUTURE_TOLERANCE = 300;

    public const VIDEO_URL_MAX = 500;
}
