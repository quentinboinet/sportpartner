<?php

namespace App\Service\Activity;

use App\Entity\Activity;
use App\Entity\GearBrand;

/**
 * Activité réduite à ce que l'export CSV écrit (ActivityRepository::exportRows() : une requête, sport, matériel,
 * marque et séance Santé joints, sans les flux JSON).
 */
final class ActivityExportRow
{
    public function __construct(
        /** Départ UTC. */
        public readonly \DateTimeImmutable $startUtc,
        /** Colonne timezone brute (IANA ou format Strava « (GMT+01:00) Europe/Paris »). */
        public readonly ?string $timezone,
        public readonly ?string $sportSlug,
        public readonly ?string $name,
        public readonly ?float $distanceMeters,
        public readonly ?int $movingSeconds,
        public readonly ?int $elapsedSeconds,
        public readonly ?float $elevationMeters,
        /** Vitesse moyenne Strava en m/s (prioritaire pour la vitesse, comme Activity::getSpeedKmh()). */
        public readonly ?float $averageSpeed,
        public readonly ?float $hrAvg,
        public readonly ?float $hrMax,
        public readonly ?string $gearBrand,
        public readonly ?string $gearModel,
        public readonly ?string $gearNickname,
        public readonly ?int $stravaId,
        public readonly bool $fromHealthSession,
    ) {
    }

    /** Départ dans le fuseau de l'activité (même règle qu'Activity::getLocalStartDate()). */
    public function localStart(): \DateTimeImmutable
    {
        return $this->startUtc->setTimezone(Activity::timeZoneFromField($this->timezone));
    }

    public function source(): ActivitySource
    {
        return ActivitySource::of($this->stravaId, $this->fromHealthSession);
    }

    /** « Marque Modèle (Surnom) » ; la marque générique « Autre » n'est pas écrite (comme Gear::getFullName()). */
    public function gearLabel(): string
    {
        if ($this->gearModel === null) {
            return '';
        }
        $full = $this->gearBrand === null || $this->gearBrand === GearBrand::OTHER ? $this->gearModel : $this->gearBrand.' '.$this->gearModel;

        return $this->gearNickname !== null && $this->gearNickname !== '' ? $full.' ('.$this->gearNickname.')' : $full;
    }

    /** Lien vers l'activité sur Strava (aucun appel : simple URL). */
    public function stravaUrl(): ?string
    {
        return $this->stravaId !== null ? 'https://www.strava.com/activities/'.$this->stravaId : null;
    }
}
