<?php

namespace App\Service\Stats;

/**
 * Deux périodes à comparer : A (la plus récente pour les préréglages) et B (la référence).
 *
 * $note précise la règle appliquée, pour l'expliquer à l'écran :
 * - NOTE_FULL_MONTH : dernier jour du mois, mois complet contre mois complet ;
 * - NOTE_CLAMPED : le mois de référence est plus court, il est pris jusqu'à son dernier jour ;
 * - NOTE_LEAP_DAY : 29 février, comparé au 28 février de l'année précédente.
 */
final class PeriodComparison
{
    public const NOTE_FULL_MONTH = 'full_month';
    public const NOTE_CLAMPED = 'clamped';
    public const NOTE_LEAP_DAY = 'leap_day';

    public function __construct(
        public readonly string $preset,
        public readonly DateRange $a,
        public readonly DateRange $b,
        /** Règle « à date » : même nombre de jours écoulés (semaine, mois, année en cours). */
        public readonly bool $toDate = false,
        public readonly ?string $note = null,
    ) {
    }

    public function sameLength(): bool
    {
        return $this->a->days() === $this->b->days();
    }
}
