<?php

namespace App\Service\Stats;

/**
 * Paramètres de /stats/comparer une fois validés : les deux périodes, le sport filtré (null = tous les sports)
 * et les erreurs de saisie à afficher (clés de traduction), après repli sur le préréglage par défaut.
 */
final class ComparisonRequest
{
    /**
     * @param list<array{key: string, params: array<string, string>}> $errors
     */
    public function __construct(
        public readonly PeriodComparison $comparison,
        public readonly ?string $sport = null,
        public readonly array $errors = [],
    ) {
    }
}
