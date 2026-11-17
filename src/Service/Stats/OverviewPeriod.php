<?php

namespace App\Service\Stats;

/**
 * Période de la vue d'ensemble des statistiques, lue dans la requête (paramètres partagés par /stats et son export
 * CSV /stats/export) :
 *  - préréglages period=7d (7 jours glissants depuis minuit), month (depuis le 1er du mois), season (depuis le
 *    1er janvier, par défaut et pour toute valeur inconnue), all (depuis le 1er janvier 2000), jusqu'à aujourd'hui ;
 *  - période personnalisée from=AAAA-MM-JJ&to=AAAA-MM-JJ (les deux, dates valides), prioritaire ; sinon repli
 *    sur le préréglage ;
 *  - bornes : premier jour à 00:00:00, dernier jour à 23:59:59 inclus (fuseau de $now) ;
 *  - granularité des graphiques : jour pour 7d et month, mois pour season et all ; période personnalisée : jour
 *    jusqu'à 31 jours, semaine jusqu'à 120, mois au-delà.
 */
final class OverviewPeriod
{
    public const PRESETS = ['7d', 'month', 'season', 'all'];
    public const DEFAULT = 'season';
    public const CUSTOM = 'custom';
    public const ALL_FROM = '2000-01-01';

    private function __construct(
        /** 7d | month | season | all | custom */
        public readonly string $period,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        /** day | week | month */
        public readonly string $granularity,
        /** Champs « du » / « au » à réafficher (saisie brute, null si absente ou invalide). */
        public readonly ?string $customFrom,
        public readonly ?string $customTo,
    ) {
    }

    /** @param array<string, mixed> $query paramètres GET */
    public static function fromQuery(array $query, \DateTimeImmutable $now): self
    {
        $raw = static fn (string $key): ?string => is_string($query[$key] ?? null) && $query[$key] !== '' ? $query[$key] : null;
        $fromParam = $raw('from');
        $toParam = $raw('to');

        $to = $now->setTime(23, 59, 59);
        $period = in_array($raw('period'), self::PRESETS, true) ? (string) $raw('period') : self::DEFAULT;
        $from = match ($period) {
            '7d'    => $now->modify('-7 days')->setTime(0, 0),
            'month' => $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->setTime(0, 0),
            'all'   => $now->setDate(2000, 1, 1)->setTime(0, 0),
            default => $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0),
        };
        $granularity = in_array($period, ['7d', 'month'], true) ? 'day' : 'month';

        if ($fromParam !== null && $toParam !== null) {
            $customFrom = self::day($fromParam, $now->getTimezone());
            $customTo = self::day($toParam, $now->getTimezone());
            if ($customFrom === null || $customTo === null) {
                return new self($period, $from, $to, $granularity, null, null);
            }
            $days = (int) $customFrom->diff($customTo)->days;

            return new self(
                self::CUSTOM,
                $customFrom,
                $customTo->setTime(23, 59, 59),
                $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month'),
                $fromParam,
                $toParam,
            );
        }

        return new self($period, $from, $to, $granularity, $fromParam, $toParam);
    }

    /**
     * Paramètres d'URL qui redonnent cette période (lien d'export).
     *
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        return $this->period === self::CUSTOM
            ? ['from' => (string) $this->customFrom, 'to' => (string) $this->customTo]
            : ['period' => $this->period];
    }

    private static function day(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }
}
