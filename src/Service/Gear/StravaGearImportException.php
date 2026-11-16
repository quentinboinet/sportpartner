<?php

namespace App\Service\Gear;

/**
 * Erreur Strava qui interrompt l'import du matériel (usage interne à StravaGearImporter, qui la
 * convertit en StravaGearImportResult::failed()).
 *
 * @internal
 */
final class StravaGearImportException extends \RuntimeException
{
    /** @param string $error Code StravaGearImportResult::ERROR_* */
    public function __construct(public readonly string $error, ?\Throwable $previous = null)
    {
        parent::__construct('Strava gear import failed: '.$error, 0, $previous);
    }
}
