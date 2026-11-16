<?php

namespace App\Service\Route;

use App\Entity\PacingIntensity;
use App\Entity\PacingStrategy;

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
 *    depuis le dernier repère qui a un temps (allure sur la distance cumulée depuis ce repère) ;
 *  - temps "fixé" (épinglé) = saisi par l'utilisateur et enregistré (RaceScenarioSplit) ; les autres repères
 *    sont "auto" : fillAuto() leur calcule un temps entre les temps fixés et le temps cible (jamais modifié) ;
 *  - stratégie d'allure (PacingStrategy + PacingIntensity, formules dans l'enum) : elle ne change que la forme des
 *    temps auto (poids effort * m(x) des tronçons, voir pacedEfforts()), jamais les temps fixés ni le temps cible.
 *
 * @phpstan-type CheckpointInput array{id: int, name: string, type: string, distanceM: int, stopSeconds: ?int, cutoffSeconds: ?int}
 * @phpstan-type Clock array{time: string, dayOffset: int}
 * @phpstan-type Row array{checkpointId: ?int, name: string, type: string, pinned: bool, distanceM: int, segmentDistanceM: int, segmentGainM: int, segmentLossM: int, cumulativeGainM: int, cumulativeLossM: int, arrivalSeconds: ?int, departureSeconds: ?int, stopSeconds: int, paceFromCheckpointId: ?int, paceSpanDistanceM: ?int, segmentMovingSeconds: ?int, segmentPaceSecPerKm: ?float, segmentSpeedKmh: ?float, avgPaceSecPerKm: ?float, paceDeviationPct: ?int, clock: ?Clock, departureClock: ?Clock, cutoffSeconds: ?int, cutoffMarginSeconds: ?int, cutoffStatus: string, errors: list<string>}
 * @phpstan-type Totals array{distanceM: int, gainM: int, lossM: int, elapsedSeconds: ?int, movingSeconds: ?int, stopSeconds: int, avgPaceSecPerKm: ?float, complete: bool}
 * @phpstan-type Result array{rows: list<Row>, finish: Row, totals: Totals, errors: list<array{checkpointId: int|null, code: string}>}
 * @phpstan-type Filled array{arrivals: array<int, int|null>, pinned: array<int, bool>}
 */
final class SplitCalculator
{
    /** Marge sous laquelle une barrière horaire est "tendue" (15 min). */
    public const TIGHT_MARGIN_SECONDS = 900;

    /** Pas d'arrondi des temps calculés par fillAuto() / distribute(). */
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
     *  - pinned : temps fixé par l'utilisateur (voir le paramètre $pinned) ; ligne "finish" : temps cible renseigné ;
     *  - arrivalSeconds (null si non saisi), departureSeconds = arrivée + stopSeconds ;
     *  - paceFromCheckpointId / paceSpanDistanceM : référence du calcul d'allure = dernier repère
     *    précédent qui a un temps (null = départ) et distance depuis ce repère ;
     *  - segmentMovingSeconds = arrivée - départ de la référence (null si négatif ou sans temps) ;
     *  - segmentPaceSecPerKm / segmentSpeedKmh : sur paceSpanDistanceM (null si durée ou distance nulle) ;
     *  - avgPaceSecPerKm : allure moyenne depuis le départ, arrêts inclus (arrivée / distance) ;
 *  - paceDeviationPct : écart de l'allure du tronçon (segmentPaceSecPerKm, non arrondie) avec l'allure moyenne en
 *    mouvement du scénario (totals.avgPaceSecPerKm, non arrondie), en % entier : floor(r * 100 - 100 + 0.5) avec
 *    r = segmentMovingSeconds * distance totale / (paceSpanDistanceM * movingSeconds total) ; > 0 = plus lent,
 *    < 0 = plus rapide ; null sans allure de tronçon ou sans allure moyenne (pas de cible, arrêts >= cible,
 *    distance nulle) ;
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
     * @param array<int, int|null>                   $arrivals    checkpointId => arrivée visée (s), null = sans temps
     *                                                            (typiquement fillAuto()['arrivals'])
     * @param array<int, bool>|null                  $pinned      checkpointId => temps fixé ? (fillAuto()['pinned']) ;
     *                                                            null : tout repère qui a une arrivée est "fixé"
     *
     * @return Result
     */
    public function compute(array $points, int $routeDistanceM, array $checkpoints, array $arrivals, ?int $targetSeconds, ?\DateTimeImmutable $startTime, string $finishName = 'Arrivée', ?array $pinned = null): array
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
                'pinned' => null !== $cp
                    ? (null !== $pinned ? ($pinned[$cp['id']] ?? false) : null !== $arrival)
                    : null !== $targetSeconds,
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
                'paceDeviationPct' => null,
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
                    $row['segmentPaceSecPerKm'] = self::round2($moving / ($span / 1000));
                    $row['segmentSpeedKmh'] = self::round2(($span / 1000) / ($moving / 3600));
                }
                if ($arrival > 0 && $distance > 0) {
                    $row['avgPaceSecPerKm'] = self::round2($arrival / ($distance / 1000));
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

        // Écart de chaque allure de tronçon avec l'allure moyenne en mouvement (connue seulement ici)
        if (null !== $moving && $finishM > 0) {
            foreach ($rows as $i => $row) {
                if (null !== $row['segmentPaceSecPerKm']) {
                    $rows[$i]['paceDeviationPct'] = self::deviationPct((int) $row['segmentMovingSeconds'], (int) $row['paceSpanDistanceM'], $moving, $finishM);
                }
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
                'avgPaceSecPerKm' => null !== $moving && $finishM > 0 ? self::round2($moving / ($finishM / 1000)) : null,
                'complete' => $complete,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Répartit un temps cible sur tous les repères (stratégie "km-effort", voir segmentEfforts(), modulée par la
     * stratégie d'allure) : c'est fillAuto() sans aucun temps fixé.
     *
     * Temps de déplacement = cible - arrêts des ravitaillements situés avant l'arrivée ; il est réparti
     * sur les tronçons au prorata de leur effort. Arrivée au repère i = déplacement cumulé jusqu'à i
     * + arrêts des ravitaillements précédents ; arrondie aux 10 s, ou à la seconde quand l'arrondi aux 10 s
     * collerait deux temps (repères à quelques mètres l'un de l'autre, du départ ou de l'arrivée).
     * Les temps sont strictement croissants d'une distance à l'autre (compute() ne signale aucune erreur
     * quand la cible laisse au moins 1 s par tronçon) et l'arrivée finale (non renvoyée) tombe exactement
     * sur la cible.
     *
     * @param list<array{int, ?float, float, float}> $points
     * @param list<CheckpointInput>                  $checkpoints
     *
     * @return array<int, int> checkpointId => arrivée visée (s)
     *
     * @throws \InvalidArgumentException 'stops_exceed_target' si les arrêts atteignent la cible
     */
    public function distribute(array $points, int $routeDistanceM, array $checkpoints, int $targetSeconds, PacingStrategy $strategy = PacingStrategy::Even, PacingIntensity $intensity = PacingIntensity::Medium): array
    {
        if ($this->totalStops($routeDistanceM, $checkpoints) >= $targetSeconds) {
            throw new \InvalidArgumentException(self::ERROR_STOPS_EXCEED_TARGET);
        }

        // Cible > arrêts : chaque repère reçoit un temps (aucun null)
        return array_map(
            static fn (?int $seconds): int => (int) $seconds,
            $this->fillAuto($points, $routeDistanceM, $checkpoints, [], $targetSeconds, $strategy, $intensity)['arrivals'],
        );
    }

    /**
     * Temps des repères "auto" (sans temps fixé) calculés à partir des temps fixés et du temps cible.
     * Règles et forme du résultat : voir fillAutoFromEfforts().
     *
     * @param list<array{int, ?float, float, float}> $points      tracé ([] sans GPX)
     * @param list<CheckpointInput>                  $checkpoints repères du parcours
     * @param array<int, int>                        $pinned      checkpointId => arrivée fixée (s)
     *
     * @return Filled
     */
    public function fillAuto(array $points, int $routeDistanceM, array $checkpoints, array $pinned, ?int $targetSeconds, PacingStrategy $strategy = PacingStrategy::Even, PacingIntensity $intensity = PacingIntensity::Medium): array
    {
        return $this->fillAutoFromEfforts($checkpoints, $routeDistanceM, $this->routeEfforts($points, $routeDistanceM, $checkpoints), $pinned, $targetSeconds, $strategy, $intensity);
    }

    /**
     * fillAuto() avec des efforts déjà calculés (routeEfforts()), pour ne pas recalculer le profil à chaque
     * scénario. Miroir exact : fillAuto() de assets/controllers/race_splits_controller.js (calcul en direct).
     *
     * Règles :
     *  - ancres = départ (0 s, sans arrêt), repères fixés (dans l'ordre des distances) et arrivée = cible si
     *    elle est renseignée. Les temps fixés et la cible sont renvoyés / utilisés tels quels, jamais modifiés ;
     *  - entre deux ancres consécutives G et D : temps disponible = arrivée(D) - départ(G) - arrêts des
     *    ravitaillements auto situés entre elles, avec départ(G) = arrivée(G) + arrêt de G (l'arrêt ne compte
     *    que pour un ravitaillement situé avant l'arrivée ; 0 pour le départ) ;
     *  - ce temps est réparti au prorata du POIDS des tronçons physiques de l'intervalle (poids[G+1..D], même
     *    formule que distribute()) : poids = effort * m(x) de la stratégie d'allure (pacedEfforts() ; = effort pour
     *    "Régulier") ; idéal(k) = départ(G) + disponible * poidsCumulé(k) / poidsTotal + arrêts des auto précédents
     *    de l'intervalle ; arrivée(k) = idéal(k) arrondi aux 10 s par floor(x / 10 + 0.5) * 10 (identique en
     *    PHP 8.3, PHP 8.4+ et JS). La stratégie ne déplace donc que les temps auto, à l'intérieur de chaque
     *    intervalle : temps fixés et cible restent exacts ;
     *  - fenêtre stricte [bas, haut], même règle que compute() (deux temps égaux seulement à la même distance) :
     *    bas = départ du point précédent (G ou auto précédent), + 1 s s'il est à une autre distance ;
     *    haut = arrivée(D) - arrêts des auto restants de l'intervalle (k compris) - 1 s par tronçon de longueur
     *    non nulle entre k et D. Si l'arrondi aux 10 s sort de la fenêtre (repères à quelques mètres l'un de
     *    l'autre, du départ ou de l'arrivée), idéal(k) est arrondi à la seconde (floor(x + 0.5)) puis ramené
     *    dans la fenêtre ;
     *  - fenêtre vide (temps fixés trop serrés pour garder 1 s par tronçon) : arrondi aux 10 s borné au sens
     *    large, >= départ du point précédent et <= arrivée(D) - arrêts des auto restants (k compris) ;
     *  - poids total nul (tronçons de longueur nulle, sans D+) : part 0, les auto tombent au départ de G ;
     *  - temps disponible <= 0 (temps fixés incohérents : non croissants, au-delà de la cible, ou arrêts trop
     *    longs) : chaque auto de l'intervalle est placé au départ du point précédent (départ de G, puis + les
     *    arrêts des auto déjà placés). compute() signale l'incohérence (not_increasing / after_finish) ;
     *  - après le dernier repère fixé, sans cible : pas d'ancre de droite, les auto restent sans temps (null).
     *
     * Propriétés : sans temps fixé = distribute() ; arrivée et temps fixés inchangés ; si chaque intervalle
     * laisse au moins 1 s par tronçon de longueur non nulle (temps fixés cohérents, cible atteignable),
     * compute() ne signale aucune erreur sur le résultat ; un temps auto qui n'est pas un multiple de 10 s est
     * à moins de 10 s du point précédent ou du suivant. Fixer un auto à sa valeur affichée n'est PAS neutre :
     * ce temps arrondi devient une ancre et les auto voisins, recalculés à partir de lui, peuvent bouger d'un
     * pas d'arrondi (ROUNDING_SECONDS).
     *
     * @param list<CheckpointInput> $checkpoints repères du parcours (triés ici : tri stable par distance)
     * @param list<float>           $efforts     n + 1 efforts dans l'ordre trié (routeEfforts())
     * @param array<int, int>       $pinned      checkpointId => arrivée fixée (s) ; ids inconnus ignorés
     * @param PacingStrategy        $strategy    stratégie d'allure (Régulier par défaut : répartition à l'effort seul)
     *
     * @return Filled arrivals : checkpointId => arrivée (fixée ou calculée ; null = aucune) pour chaque repère,
     *                pinned : checkpointId => true si le temps est fixé, false s'il est auto
     *
     * @throws \InvalidArgumentException si $efforts n'a pas n + 1 valeurs
     */
    public function fillAutoFromEfforts(array $checkpoints, int $routeDistanceM, array $efforts, array $pinned, ?int $targetSeconds, PacingStrategy $strategy = PacingStrategy::Even, PacingIntensity $intensity = PacingIntensity::Medium): array
    {
        $checkpoints = self::sortCheckpoints($checkpoints);
        $finishM = self::finishDistance($routeDistanceM, $checkpoints);
        $n = \count($checkpoints);
        if (\count($efforts) !== $n + 1) {
            throw new \InvalidArgumentException(sprintf('Expected %d efforts, got %d.', $n + 1, \count($efforts)));
        }
        // Poids des tronçons : effort modulé par la stratégie d'allure
        $efforts = self::pacedEfforts($checkpoints, $finishM, $efforts, $strategy, $intensity);

        $arrivals = [];
        $isPinned = [];
        $stops = [];
        foreach ($checkpoints as $i => $cp) {
            $stops[$i] = self::effectiveStop($cp, $finishM);
            $arrivals[$cp['id']] = $pinned[$cp['id']] ?? null;
            $isPinned[$cp['id']] = isset($pinned[$cp['id']]);
        }

        $left = -1; // ancre de gauche (-1 = départ)
        $leftDeparture = 0;
        for ($right = 0; $right <= $n; ++$right) {
            if ($right < $n && !$isPinned[$checkpoints[$right]['id']]) {
                continue;
            }
            $rightArrival = $right < $n ? (int) $arrivals[$checkpoints[$right]['id']] : $targetSeconds;

            if (null !== $rightArrival) {
                $stopsBetween = 0;
                for ($k = $left + 1; $k < $right; ++$k) {
                    $stopsBetween += $stops[$k];
                }
                $available = $rightArrival - $leftDeparture - $stopsBetween;
                $totalEffort = 0.0;
                for ($k = $left + 1; $k <= $right; ++$k) {
                    $totalEffort += $efforts[$k];
                }

                // Tronçons de longueur non nulle entre chaque auto et l'ancre de droite : chacun doit garder
                // au moins 1 s (compute() refuse deux temps égaux à des distances différentes)
                $strictAfter = [];
                $nextDistance = $right < $n ? $checkpoints[$right]['distanceM'] : $finishM;
                $strictCount = 0;
                for ($k = $right - 1; $k > $left; --$k) {
                    $strictCount += $nextDistance > $checkpoints[$k]['distanceM'] ? 1 : 0;
                    $strictAfter[$k] = $strictCount;
                    $nextDistance = $checkpoints[$k]['distanceM'];
                }

                $cumEffort = 0.0;
                $stopsBefore = 0;
                $previousDeparture = $leftDeparture;
                $previousDistance = $left >= 0 ? $checkpoints[$left]['distanceM'] : 0;
                for ($k = $left + 1; $k < $right; ++$k) {
                    if ($available <= 0) {
                        $arrival = $previousDeparture;
                    } else {
                        $cumEffort += $efforts[$k];
                        $share = $totalEffort > 0 ? $cumEffort / $totalEffort : 0.0;
                        $ideal = $leftDeparture + $available * $share + $stopsBefore;
                        $arrival = (int) (floor($ideal / self::ROUNDING_SECONDS + 0.5) * self::ROUNDING_SECONDS);
                        // Il faut encore pouvoir faire les arrêts restants avant l'ancre de droite
                        $latest = $rightArrival - $stopsBetween + $stopsBefore;
                        // Fenêtre stricte : 1 s après le départ du point précédent s'il est à une autre distance,
                        // et 1 s gardée pour chaque tronçon non nul restant
                        $low = $previousDeparture + ($checkpoints[$k]['distanceM'] > $previousDistance ? 1 : 0);
                        $high = $latest - $strictAfter[$k];
                        if ($low <= $high) {
                            if ($arrival < $low || $arrival > $high) {
                                // L'arrondi aux 10 s collerait deux temps (repères à quelques mètres) : à la seconde
                                $arrival = min(max((int) floor($ideal + 0.5), $low), $high);
                            }
                        } else {
                            // Temps fixés trop serrés pour 1 s par tronçon : seulement croissant au sens large
                            $arrival = min(max($arrival, $previousDeparture), $latest);
                        }
                    }
                    $arrivals[$checkpoints[$k]['id']] = $arrival;
                    $stopsBefore += $stops[$k];
                    $previousDeparture = $arrival + $stops[$k];
                    $previousDistance = $checkpoints[$k]['distanceM'];
                }
            }

            if ($right < $n) {
                $left = $right;
                $leftDeparture = (int) $rightArrival + $stops[$right];
            }
        }

        return ['arrivals' => $arrivals, 'pinned' => $isPinned];
    }

    /**
     * Poids des n + 1 tronçons pour une stratégie d'allure : effort_i * m(x_i), avec x_i = position du milieu du
     * tronçon i dans la course = (début + fin) / (2 * distance de l'arrivée), dans [0, 1] (0 si la distance est
     * nulle) ; m = PacingStrategy::multiplier(). "Régulier" : efforts renvoyés tels quels.
     * Miroir exact : pacedEfforts() de assets/controllers/race_splits_controller.js.
     *
     * @param list<CheckpointInput> $checkpoints repères triés par distance
     * @param list<float>           $efforts     n + 1 efforts dans le même ordre
     *
     * @return list<float>
     */
    public static function pacedEfforts(array $checkpoints, int $finishM, array $efforts, PacingStrategy $strategy, PacingIntensity $intensity): array
    {
        if (PacingStrategy::Even === $strategy) {
            return $efforts;
        }

        $weights = [];
        $previous = 0;
        $n = \count($checkpoints);
        foreach ($efforts as $i => $effort) {
            $distance = $i < $n ? $checkpoints[$i]['distanceM'] : $finishM;
            $x = $finishM > 0 ? ($previous + $distance) / (2 * $finishM) : 0.0;
            $weights[] = $effort * $strategy->multiplier((float) $x, $intensity);
            $previous = $distance;
        }

        return $weights;
    }

    /**
     * Somme des arrêts prévus (ravitaillements situés avant l'arrivée).
     *
     * @param list<CheckpointInput> $checkpoints
     */
    public function totalStops(int $routeDistanceM, array $checkpoints): int
    {
        $finishM = self::finishDistance($routeDistanceM, self::sortCheckpoints($checkpoints));
        $total = 0;
        foreach ($checkpoints as $cp) {
            $total += self::effectiveStop($cp, $finishM);
        }

        return $total;
    }

    /**
     * Efforts des n + 1 tronçons (repères triés par distance, tri stable) : entrée de fillAutoFromEfforts(),
     * transmise telle quelle au calcul en direct du navigateur.
     *
     * @param list<array{int, ?float, float, float}> $points
     * @param list<CheckpointInput>                  $checkpoints
     *
     * @return list<float>
     */
    public function routeEfforts(array $points, int $routeDistanceM, array $checkpoints): array
    {
        $checkpoints = self::sortCheckpoints($checkpoints);

        return $this->segmentEfforts($points, $checkpoints, self::finishDistance($routeDistanceM, $checkpoints));
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

    /**
     * Arrondi à 0,01 des allures et vitesses (valeurs > 0) : floor(x * 100 + 0.5) / 100. Mêmes opérations
     * flottantes qu'en JS (race_splits_controller.js), donc même résultat en PHP 8.3, PHP 8.4+ et dans le
     * navigateur ; round() a changé en PHP 8.4 (plus de pré-arrondi) et diffère près des demi-centièmes.
     */
    private static function round2(float $value): float
    {
        return floor($value * 100 + 0.5) / 100;
    }

    /**
     * Écart en % entier entre l'allure d'un tronçon (moving s sur span m) et l'allure moyenne en mouvement
     * (totalMoving s sur finishM m) : floor(r * 100 - 100 + 0.5), r = moving * finishM / (span * totalMoving).
     * Mêmes opérations qu'en JS (produits entiers exacts, une seule division flottante).
     */
    private static function deviationPct(int $moving, int $span, int $totalMoving, int $finishM): int
    {
        return (int) floor($moving * $finishM / ($span * $totalMoving) * 100 - 100 + 0.5);
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
