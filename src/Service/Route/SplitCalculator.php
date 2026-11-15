<?php

namespace App\Service\Route;

/**
 * Calcul pur (sans base de données) des temps de passage d'un scénario de course : source unique
 * utilisée par la page plan, la page d'impression et (plus tard) l'estimation automatique (phase 3).
 *
 * Conventions :
 *  - tous les temps sont des secondes écoulées depuis le départ (peuvent dépasser 24 h) ;
 *  - "arrivée" = temps visé à l'arrivée au repère ; "départ" = arrivée + arrêt prévu au ravitaillement ;
 *  - l'arrêt n'est compté que pour un ravitaillement (type aid_station) situé strictement avant l'arrivée ;
 *  - l'arrivée finale (ligne "finish") a pour temps le temps cible du scénario ;
 *  - un temps manquant n'est pas une erreur (plan incomplet autorisé) : le segment suivant est calculé
 *    depuis le dernier repère qui a un temps (allure sur la distance cumulée depuis ce repère).
 *
 * @phpstan-type CheckpointInput array{id: int, name: string, type: string, distanceM: int, stopSeconds: ?int, cutoffSeconds: ?int}
 * @phpstan-type Clock array{time: string, dayOffset: int}
 * @phpstan-type Row array{checkpointId: ?int, name: string, type: string, distanceM: int, segmentDistanceM: int, segmentGainM: int, segmentLossM: int, cumulativeGainM: int, cumulativeLossM: int, arrivalSeconds: ?int, departureSeconds: ?int, stopSeconds: int, paceFromCheckpointId: ?int, paceSpanDistanceM: ?int, segmentMovingSeconds: ?int, segmentPaceSecPerKm: ?float, segmentSpeedKmh: ?float, avgPaceSecPerKm: ?float, clock: ?Clock, departureClock: ?Clock, cutoffSeconds: ?int, cutoffMarginSeconds: ?int, cutoffStatus: string, errors: list<string>}
 * @phpstan-type Totals array{distanceM: int, gainM: int, lossM: int, elapsedSeconds: ?int, movingSeconds: ?int, stopSeconds: int, avgPaceSecPerKm: ?float, complete: bool}
 * @phpstan-type Result array{rows: list<Row>, finish: Row, totals: Totals, errors: list<array{checkpointId: int|null, code: string}>}
 */
final class SplitCalculator
{
    /** Marge sous laquelle une barrière horaire est "tendue" (15 min). */
    public const TIGHT_MARGIN_SECONDS = 900;

    /** Pas d'arrondi des temps proposés par distribute(). */
    public const ROUNDING_SECONDS = 10;

    /** Stratégie "km-effort" : 100 m de D+ comptent comme 1 km à plat. */
    public const GAIN_M_PER_EFFORT_KM = 100;

    public const TYPE_AID_STATION = 'aid_station';
    public const TYPE_FINISH = 'finish';

    public const ERROR_NOT_INCREASING = 'not_increasing';
    public const ERROR_AFTER_FINISH = 'after_finish';
    public const ERROR_STOPS_EXCEED_TARGET = 'stops_exceed_target';

    public const CUTOFF_NONE = 'none';
    public const CUTOFF_OK = 'ok';
    public const CUTOFF_TIGHT = 'tight';
    public const CUTOFF_MISSED = 'missed';

    public function __construct(private readonly RouteAnalyzer $analyzer = new RouteAnalyzer())
    {
    }

    /**
     * Tableau des temps de passage d'un scénario.
     *
     * Une ligne par repère (dans l'ordre des distances) + une ligne "finish" (checkpointId null,
     * type "finish", arrivée = $targetSeconds, distance = max($routeDistanceM, dernier repère)).
     *
     * Champs d'une ligne :
     *  - segmentDistanceM / segmentGainM / segmentLossM : tronçon physique depuis la ligne précédente
     *    (ou le départ) ; D+/D- à 0 sans tracé ;
     *  - arrivalSeconds (null si non saisi), departureSeconds = arrivée + stopSeconds ;
     *  - paceFromCheckpointId / paceSpanDistanceM : référence du calcul d'allure = dernier repère
     *    précédent qui a un temps (null = départ) et distance depuis ce repère ;
     *  - segmentMovingSeconds = arrivée - départ de la référence (null si négatif ou sans temps) ;
     *  - segmentPaceSecPerKm / segmentSpeedKmh : sur paceSpanDistanceM (null si durée ou distance nulle) ;
     *  - avgPaceSecPerKm : allure moyenne depuis le départ, arrêts inclus (arrivée / distance) ;
     *  - clock / departureClock : heure d'horloge (si heure de départ connue) ;
     *  - cutoffMarginSeconds = barrière - arrivée ; cutoffStatus none|ok|tight (< 15 min)|missed (< 0) ;
     *  - errors : codes d'erreur de la ligne (voir ci-dessous).
     *
     * Erreurs (liste globale "errors" + "errors" de chaque ligne) :
     *  - not_increasing : arrivée < départ du repère de référence, ou égale alors que la distance
     *    depuis ce repère est non nulle (deux repères au même endroit peuvent avoir le même temps) ;
     *  - after_finish : arrivée à un repère > temps cible (ou égale avant la ligne d'arrivée) ;
     *  - stops_exceed_target (checkpointId null) : la somme des arrêts atteint le temps cible.
     *
     * Totaux : distanceM, gainM, lossM (somme des tronçons), elapsedSeconds (= temps cible),
     * stopSeconds (arrêts prévus), movingSeconds (= cible - arrêts, null sans cible ou si <= 0),
     * avgPaceSecPerKm (allure de déplacement : movingSeconds / distance), complete (tous les repères
     * et l'arrivée ont un temps).
     *
     * @param list<array{int, ?float, float, float}> $points      tracé ([] sans GPX)
     * @param int                                    $routeDistanceM longueur du tracé, ou distance de la course sans GPX
     * @param list<CheckpointInput>                  $checkpoints repères du parcours (hors repères au-delà de l'arrivée)
     * @param array<int, int>                        $arrivals    checkpointId => arrivée visée (s)
     *
     * @return Result
     */
    public function compute(array $points, int $routeDistanceM, array $checkpoints, array $arrivals, ?int $targetSeconds, ?\DateTimeImmutable $startTime, string $finishName = 'Arrivée'): array
    {
        $checkpoints = self::sortCheckpoints($checkpoints);
        $finishM = self::finishDistance($routeDistanceM, $checkpoints);
        $segments = $this->physicalSegments($points, $checkpoints, $finishM);
        $n = \count($checkpoints);

        $rows = [];
        $errors = [];
        $prevDistance = 0;
        $cumGain = 0;
        $cumLoss = 0;
        $refId = null;
        $refDistance = 0;
        $refDeparture = 0;
        $totalStops = 0;
        $complete = null !== $targetSeconds;

        for ($i = 0; $i <= $n; ++$i) {
            $cp = $i < $n ? $checkpoints[$i] : null;
            $distance = null !== $cp ? $cp['distanceM'] : $finishM;
            $arrival = null !== $cp ? ($arrivals[$cp['id']] ?? null) : $targetSeconds;
            $stop = null !== $cp ? self::effectiveStop($cp, $finishM) : 0;
            $totalStops += $stop;
            if (null === $arrival) {
                $complete = false;
            }

            $cumGain += $segments[$i]['gain'];
            $cumLoss += $segments[$i]['loss'];

            $row = [
                'checkpointId' => $cp['id'] ?? null,
                'name' => null !== $cp ? $cp['name'] : $finishName,
                'type' => null !== $cp ? $cp['type'] : self::TYPE_FINISH,
                'distanceM' => $distance,
                'segmentDistanceM' => $distance - $prevDistance,
                'segmentGainM' => $segments[$i]['gain'],
                'segmentLossM' => $segments[$i]['loss'],
                'cumulativeGainM' => $cumGain,
                'cumulativeLossM' => $cumLoss,
                'arrivalSeconds' => $arrival,
                'departureSeconds' => null,
                'stopSeconds' => $stop,
                'paceFromCheckpointId' => null,
                'paceSpanDistanceM' => null,
                'segmentMovingSeconds' => null,
                'segmentPaceSecPerKm' => null,
                'segmentSpeedKmh' => null,
                'avgPaceSecPerKm' => null,
                'clock' => null,
                'departureClock' => null,
                'cutoffSeconds' => $cp['cutoffSeconds'] ?? null,
                'cutoffMarginSeconds' => null,
                'cutoffStatus' => self::CUTOFF_NONE,
                'errors' => [],
            ];
            $prevDistance = $distance;

            if (null !== $arrival) {
                $departure = $arrival + $stop;
                $span = $distance - $refDistance;
                $moving = $arrival - $refDeparture;

                $row['departureSeconds'] = $departure;
                $row['paceFromCheckpointId'] = $refId;
                $row['paceSpanDistanceM'] = $span;
                $row['segmentMovingSeconds'] = $moving >= 0 ? $moving : null;
                if ($moving > 0 && $span > 0) {
                    $row['segmentPaceSecPerKm'] = round($moving / ($span / 1000), 2);
                    $row['segmentSpeedKmh'] = round(($span / 1000) / ($moving / 3600), 2);
                }
                if ($arrival > 0 && $distance > 0) {
                    $row['avgPaceSecPerKm'] = round($arrival / ($distance / 1000), 2);
                }
                if (null !== $startTime) {
                    $row['clock'] = DurationFormat::formatClock($startTime, $arrival);
                    $row['departureClock'] = DurationFormat::formatClock($startTime, $departure);
                }
                if (null !== $row['cutoffSeconds']) {
                    $margin = $row['cutoffSeconds'] - $arrival;
                    $row['cutoffMarginSeconds'] = $margin;
                    $row['cutoffStatus'] = $margin < 0 ? self::CUTOFF_MISSED
                        : ($margin < self::TIGHT_MARGIN_SECONDS ? self::CUTOFF_TIGHT : self::CUTOFF_OK);
                }

                if ($moving < 0 || (0 === $moving && $span > 0)) {
                    $row['errors'][] = self::ERROR_NOT_INCREASING;
                }
                if (null !== $cp && null !== $targetSeconds
                    && ($arrival > $targetSeconds || ($arrival === $targetSeconds && $distance < $finishM))) {
                    $row['errors'][] = self::ERROR_AFTER_FINISH;
                }
                foreach ($row['errors'] as $code) {
                    $errors[] = ['checkpointId' => $row['checkpointId'], 'code' => $code];
                }

                $refId = $row['checkpointId'];
                $refDistance = $distance;
                $refDeparture = $departure;
            }

            $rows[] = $row;
        }

        $moving = null;
        if (null !== $targetSeconds) {
            if ($totalStops >= $targetSeconds) {
                $errors[] = ['checkpointId' => null, 'code' => self::ERROR_STOPS_EXCEED_TARGET];
            } else {
                $moving = $targetSeconds - $totalStops;
            }
        }

        $finish = array_pop($rows);

        return [
            'rows' => $rows,
            'finish' => $finish,
            'totals' => [
                'distanceM' => $finishM,
                'gainM' => $cumGain,
                'lossM' => $cumLoss,
                'elapsedSeconds' => $targetSeconds,
                'movingSeconds' => $moving,
                'stopSeconds' => $totalStops,
                'avgPaceSecPerKm' => null !== $moving && $finishM > 0 ? round($moving / ($finishM / 1000), 2) : null,
                'complete' => $complete,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Répartit un temps cible sur les repères (stratégie "km-effort", voir segmentEfforts()).
     *
     * Temps de déplacement = cible - arrêts des ravitaillements situés avant l'arrivée ; il est réparti
     * sur les tronçons au prorata de leur effort. Arrivée au repère i = déplacement cumulé jusqu'à i
     * + arrêts des ravitaillements précédents ; arrondie aux 10 s (sans dépasser ce que permet la cible).
     * Les temps sont croissants et l'arrivée finale (non renvoyée) tombe exactement sur la cible.
     *
     * @param list<array{int, ?float, float, float}> $points
     * @param list<CheckpointInput>                  $checkpoints
     *
     * @return array<int, int> checkpointId => arrivée visée (s)
     *
     * @throws \InvalidArgumentException 'stops_exceed_target' si les arrêts atteignent la cible
     */
    public function distribute(array $points, int $routeDistanceM, array $checkpoints, int $targetSeconds): array
    {
        $checkpoints = self::sortCheckpoints($checkpoints);
        $finishM = self::finishDistance($routeDistanceM, $checkpoints);

        $totalStops = 0;
        foreach ($checkpoints as $cp) {
            $totalStops += self::effectiveStop($cp, $finishM);
        }
        if ($totalStops >= $targetSeconds) {
            throw new \InvalidArgumentException(self::ERROR_STOPS_EXCEED_TARGET);
        }
        $moving = $targetSeconds - $totalStops;

        $efforts = $this->segmentEfforts($points, $checkpoints, $finishM);
        $totalEffort = array_sum($efforts);

        $result = [];
        $cumEffort = 0.0;
        $stopsBefore = 0;
        foreach ($checkpoints as $i => $cp) {
            $cumEffort += $efforts[$i];
            $share = $totalEffort > 0 ? $cumEffort / $totalEffort : 0.0;
            $arrival = (int) (round(($moving * $share + $stopsBefore) / self::ROUNDING_SECONDS) * self::ROUNDING_SECONDS);
            // Plafond : il faut encore pouvoir faire les arrêts restants avant la cible
            $result[$cp['id']] = min($arrival, $targetSeconds - $totalStops + $stopsBefore);
            $stopsBefore += self::effectiveStop($cp, $finishM);
        }

        return $result;
    }

    /**
     * Effort de chaque tronçon (départ -> repère 1 -> ... -> arrivée) : n + 1 valeurs.
     *
     * Stratégie "km-effort" : effort = km + D+ / 100 (100 m de montée "coûtent" 1 km à plat), la descente
     * est ignorée. Point d'extension : c'est la seule méthode à remplacer pour une autre répartition
     * (ex. modèle d'allure issu de l'historique de l'athlète en phase 3).
     *
     * @param list<array{int, ?float, float, float}> $points
     * @param list<CheckpointInput>                  $checkpoints triés par distance
     *
     * @return list<float>
     */
    public function segmentEfforts(array $points, array $checkpoints, int $finishDistanceM): array
    {
        $efforts = [];
        $prev = 0;
        $segments = $this->physicalSegments($points, $checkpoints, $finishDistanceM);
        foreach ($segments as $i => $segment) {
            $distance = $i < \count($checkpoints) ? $checkpoints[$i]['distanceM'] : $finishDistanceM;
            $efforts[] = (float) (max(0, $distance - $prev) / 1000 + $segment['gain'] / self::GAIN_M_PER_EFFORT_KM);
            $prev = $distance;
        }

        return $efforts;
    }

    /**
     * D+/D- des n + 1 tronçons physiques (départ -> repères -> arrivée). Zéros sans tracé.
     *
     * @param list<array{int, ?float, float, float}> $points
     * @param list<CheckpointInput>                  $checkpoints triés par distance
     *
     * @return list<array{gain: int, loss: int}>
     */
    private function physicalSegments(array $points, array $checkpoints, int $finishM): array
    {
        if ([] === $points) {
            return array_fill(0, \count($checkpoints) + 1, ['gain' => 0, 'loss' => 0]);
        }

        // segments() calcule le profil filtré une seule fois (même filtre que gainLoss())
        $marks = array_map(static fn (array $cp): array => ['name' => $cp['name'], 'distanceM' => $cp['distanceM']], $checkpoints);

        return array_map(
            static fn (array $s): array => ['gain' => $s['gainM'], 'loss' => $s['lossM']],
            $this->analyzer->segments($points, $marks, $finishM),
        );
    }

    /**
     * @param list<CheckpointInput> $checkpoints
     *
     * @return list<CheckpointInput>
     */
    private static function sortCheckpoints(array $checkpoints): array
    {
        $checkpoints = array_values($checkpoints);
        usort($checkpoints, static fn (array $a, array $b): int => $a['distanceM'] <=> $b['distanceM']); // tri stable

        return $checkpoints;
    }

    /** @param list<CheckpointInput> $checkpoints triés */
    private static function finishDistance(int $routeDistanceM, array $checkpoints): int
    {
        $last = [] === $checkpoints ? 0 : $checkpoints[\count($checkpoints) - 1]['distanceM'];

        return max(0, $routeDistanceM, $last);
    }

    /** @param CheckpointInput $cp */
    private static function effectiveStop(array $cp, int $finishM): int
    {
        if (self::TYPE_AID_STATION !== $cp['type'] || $cp['distanceM'] >= $finishM) {
            return 0;
        }

        return max(0, (int) $cp['stopSeconds']);
    }
}
