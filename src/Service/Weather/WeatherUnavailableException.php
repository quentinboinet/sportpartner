<?php

declare(strict_types=1);

namespace App\Service\Weather;

/**
 * Échec d'un appel Open-Meteo (réseau, délai dépassé, statut HTTP, réponse sans données).
 * Interne à RaceWeatherService : converti en état « unavailable », jamais propagé à la page.
 * Le code est le statut HTTP (0 pour une erreur réseau ou une réponse illisible).
 */
final class WeatherUnavailableException extends \RuntimeException
{
}
