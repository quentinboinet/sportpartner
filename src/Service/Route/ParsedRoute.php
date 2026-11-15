<?php

namespace App\Service\Route;

/**
 * Résultat de GpxParser::parse(), prêt à être stocké.
 *
 * Format d'un point : [distanceM (int, cumulée depuis 0), ele (?float, 1 décimale), lat (float, 6 décimales), lon (float, 6 décimales)].
 */
final class ParsedRoute
{
    /**
     * @param list<array{int, ?float, float, float}> $points points ré-échantillonnés, distance non décroissante
     */
    public function __construct(
        public readonly array $points,
        public readonly int $distanceM,
        public readonly int $gainM,
        public readonly int $lossM,
        public readonly ?float $minEle,
        public readonly ?float $maxEle,
        public readonly ?string $name,
    ) {
    }
}
