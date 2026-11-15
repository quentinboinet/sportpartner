<?php

namespace App\Service\Route;

/**
 * Calculs purs sur un tracé stocké : list<[distanceM, ele|null, lat, lon]>, distance non décroissante.
 *
 * Dénivelé : le profil brut (GPS/baro) est bruité, sommer toutes les variations surestime fortement le D+.
 * On applique UN seul filtre à hystérésis (seuil NOISE_THRESHOLD_M) sur le profil complet :
 *  1. on repère les points de retournement « confirmés » (zigzag) : un sens (montée/descente) n'est acté
 *     qu'après une variation ≥ seuil, une inversion n'est actée qu'après un retour ≥ seuil depuis l'extrême ;
 *  2. entre deux retournements, l'altitude filtrée suit le maximum (montée) ou le minimum (descente) courant
 *     de l'altitude brute, borné aux deux extrêmes : profil monotone par tronçon, qui garde la répartition
 *     réelle du dénivelé le long de la distance (utile au futur modèle d'allure par segment).
 * Le D+/D- d'un intervalle [from, to] est la variation positive/négative de ce profil filtré (fonction fixe,
 * linéaire entre les points) : il est donc additif. La somme des D+ des segments vaut le D+ total,
 * à l'arrondi au mètre près par segment.
 * Effet : une montée propre de 500 m donne 500 m ; un plat bruité de ±1 m donne 0 ; un replat ou une bosse
 * de moins de 3 m est ignoré (choix assumé, comparable à ce que font les montres/Strava).
 */
final class RouteAnalyzer
{
    public const NOISE_THRESHOLD_M = 3.0;

    /**
     * Altitude interpolée linéairement à une distance donnée (bornée au tracé). Null si aucune altitude.
     *
     * @param list<array{int, ?float, float, float}> $points
     */
    public function elevationAt(array $points, int $distanceM): ?float
    {
        [$dist, $ele] = $this->elevationSeries($points);

        return $this->interpolate($dist, $ele, $distanceM);
    }

    /**
     * Position [lat, lon] interpolée à une distance donnée (bornée au tracé). Null si tracé vide.
     *
     * @param list<array{int, ?float, float, float}> $points
     *
     * @return array{float, float}|null
     */
    public function positionAt(array $points, int $distanceM): ?array
    {
        if ([] === $points) {
            return null;
        }
        $dist = [];
        $lat = [];
        $lon = [];
        foreach ($points as $p) {
            $dist[] = (float) $p[0];
            $lat[] = (float) $p[2];
            $lon[] = (float) $p[3];
        }
        $la = $this->interpolate($dist, $lat, $distanceM);
        $lo = $this->interpolate($dist, $lon, $distanceM);
        if (null === $la || null === $lo) {
            return null;
        }

        return [round($la, 6), round($lo, 6)];
    }

    /**
     * D+/D- (m, arrondis) sur [fromM, toM], après filtrage à hystérésis du profil complet.
     *
     * @param list<array{int, ?float, float, float}> $points
     *
     * @return array{gain: int, loss: int}
     */
    public function gainLoss(array $points, int $fromM, int $toM): array
    {
        [$dist, $filtered] = $this->filteredProfile($points);
        [$gain, $loss] = $this->variation($dist, $filtered, $fromM, $toM);

        return ['gain' => (int) round($gain), 'loss' => (int) round($loss)];
    }

    /**
     * Découpe le tracé en segments entre repères consécutifs (Départ → repères triés → Arrivée).
     * Les repères sont bornés à [0, total] ; deux repères à la même distance donnent un segment de longueur 0.
     *
     * @param list<array{int, ?float, float, float}>    $points
     * @param list<array{name: string, distanceM: int}> $marks  triés ou non
     *
     * @return list<array{fromName: string, toName: string, fromDistanceM: int, toDistanceM: int, distanceM: int, gainM: int, lossM: int, startEle: ?float, endEle: ?float, avgGradePct: ?float}>
     */
    public function segments(array $points, array $marks, int $totalDistanceM, string $startName = 'Départ', string $finishName = 'Arrivée'): array
    {
        $total = max(0, $totalDistanceM);

        $sorted = [];
        foreach (array_values($marks) as $i => $mark) {
            $sorted[] = ['name' => $mark['name'], 'distanceM' => max(0, min($total, $mark['distanceM'])), 'order' => $i];
        }
        usort($sorted, static fn (array $a, array $b): int => [$a['distanceM'], $a['order']] <=> [$b['distanceM'], $b['order']]);

        $bounds = [['name' => $startName, 'distanceM' => 0]];
        foreach ($sorted as $mark) {
            $bounds[] = ['name' => $mark['name'], 'distanceM' => $mark['distanceM']];
        }
        $bounds[] = ['name' => $finishName, 'distanceM' => $total];

        [$eleDist, $ele] = $this->elevationSeries($points);
        [$profDist, $filtered] = $this->filteredProfile($points);

        $segments = [];
        for ($i = 1, $n = \count($bounds); $i < $n; ++$i) {
            $from = $bounds[$i - 1];
            $to = $bounds[$i];
            $length = $to['distanceM'] - $from['distanceM'];
            [$gain, $loss] = $this->variation($profDist, $filtered, $from['distanceM'], $to['distanceM']);
            $startEle = $this->interpolate($eleDist, $ele, $from['distanceM']);
            $endEle = $this->interpolate($eleDist, $ele, $to['distanceM']);

            $segments[] = [
                'fromName' => $from['name'],
                'toName' => $to['name'],
                'fromDistanceM' => $from['distanceM'],
                'toDistanceM' => $to['distanceM'],
                'distanceM' => $length,
                'gainM' => (int) round($gain),
                'lossM' => (int) round($loss),
                'startEle' => null === $startEle ? null : round($startEle, 1),
                'endEle' => null === $endEle ? null : round($endEle, 1),
                'avgGradePct' => $length > 0 && null !== $startEle && null !== $endEle
                    ? round(($endEle - $startEle) / $length * 100, 1)
                    : null,
            ];
        }

        return $segments;
    }

    /**
     * Distances et altitudes des seuls points ayant une altitude.
     *
     * @param list<array{int, ?float, float, float}> $points
     *
     * @return array{list<float>, list<float>}
     */
    private function elevationSeries(array $points): array
    {
        $dist = [];
        $ele = [];
        foreach ($points as $p) {
            if (null !== $p[1]) {
                $dist[] = (float) $p[0];
                $ele[] = (float) $p[1];
            }
        }

        return [$dist, $ele];
    }

    /**
     * Profil d'altitude filtré (hystérésis + monotonie par tronçon), voir doc de classe.
     *
     * @param list<array{int, ?float, float, float}> $points
     *
     * @return array{list<float>, list<float>}
     */
    private function filteredProfile(array $points): array
    {
        [$dist, $ele] = $this->elevationSeries($points);
        $n = \count($ele);
        if ($n < 2) {
            return [$dist, $ele];
        }

        // 1. Points de retournement confirmés (zigzag à seuil).
        $t = self::NOISE_THRESHOLD_M;
        $anchors = [0];
        $dir = 0;
        $cand = 0;
        for ($k = 1; $k < $n; ++$k) {
            $e = $ele[$k];
            if (0 === $dir) {
                if ($e - $ele[0] >= $t) {
                    $dir = 1;
                    $cand = $k;
                } elseif ($ele[0] - $e >= $t) {
                    $dir = -1;
                    $cand = $k;
                }
            } elseif (1 === $dir) {
                if ($e > $ele[$cand]) {
                    $cand = $k;
                } elseif ($ele[$cand] - $e >= $t) {
                    $anchors[] = $cand;
                    $dir = -1;
                    $cand = $k;
                }
            } elseif ($e < $ele[$cand]) {
                $cand = $k;
            } elseif ($e - $ele[$cand] >= $t) {
                $anchors[] = $cand;
                $dir = 1;
                $cand = $k;
            }
        }
        if (0 !== $dir) {
            $anchors[] = $cand;
        }

        // 2. Entre deux retournements : max (montée) / min (descente) courant, borné aux extrêmes.
        $filtered = array_fill(0, $n, $ele[0]);
        for ($a = 1, $na = \count($anchors); $a < $na; ++$a) {
            $i0 = $anchors[$a - 1];
            $i1 = $anchors[$a];
            $lo = min($ele[$i0], $ele[$i1]);
            $hi = max($ele[$i0], $ele[$i1]);
            $up = $ele[$i1] >= $ele[$i0];
            $run = $ele[$i0];
            for ($j = $i0; $j <= $i1; ++$j) {
                $run = $up ? max($run, $ele[$j]) : min($run, $ele[$j]);
                $filtered[$j] = max($lo, min($hi, $run));
            }
        }
        // Après le dernier retournement : variation non confirmée (< seuil) → palier.
        $last = $anchors[\count($anchors) - 1];
        for ($j = $last + 1; $j < $n; ++$j) {
            $filtered[$j] = $ele[$last];
        }

        return [$dist, $filtered];
    }

    /**
     * Variations positive et négative d'une fonction linéaire par morceaux sur [from, to].
     * Valeur en un point de distance dupliquée = dernier point (continuité à droite) → additivité exacte.
     *
     * @param list<float> $dist
     * @param list<float> $values
     *
     * @return array{float, float}
     */
    private function variation(array $dist, array $values, int $fromM, int $toM): array
    {
        if (\count($values) < 2) {
            return [0.0, 0.0];
        }
        if ($fromM > $toM) {
            [$fromM, $toM] = [$toM, $fromM];
        }
        $from = max($dist[0], min($dist[\count($dist) - 1], (float) $fromM));
        $to = max($dist[0], min($dist[\count($dist) - 1], (float) $toM));

        $prev = $this->interpolate($dist, $values, $from);
        $gain = 0.0;
        $loss = 0.0;
        $start = $this->lastIndexAtOrBefore($dist, $from) + 1;
        $endExact = false;
        for ($i = $start, $n = \count($dist); $i < $n && $dist[$i] <= $to; ++$i) {
            $diff = $values[$i] - $prev;
            if ($diff > 0) {
                $gain += $diff;
            } else {
                $loss -= $diff;
            }
            $prev = $values[$i];
            $endExact = $dist[$i] === $to;
        }
        if (!$endExact) {
            $diff = (float) $this->interpolate($dist, $values, $to) - $prev;
            if ($diff > 0) {
                $gain += $diff;
            } else {
                $loss -= $diff;
            }
        }

        return [$gain, $loss];
    }

    /**
     * Interpolation linéaire bornée ; sur une distance dupliquée, prend le dernier point.
     *
     * @param list<float> $dist
     * @param list<float> $values
     */
    private function interpolate(array $dist, array $values, float|int $x): ?float
    {
        $n = \count($values);
        if (0 === $n) {
            return null;
        }
        if ($x <= $dist[0]) {
            return $values[$this->lastIndexAtOrBefore($dist, $dist[0])];
        }
        if ($x >= $dist[$n - 1]) {
            return $values[$n - 1];
        }
        $i = $this->lastIndexAtOrBefore($dist, (float) $x);
        if ($dist[$i] === (float) $x) {
            return $values[$i];
        }
        $span = $dist[$i + 1] - $dist[$i];
        $ratio = $span > 0 ? ($x - $dist[$i]) / $span : 0.0;

        return $values[$i] + ($values[$i + 1] - $values[$i]) * $ratio;
    }

    /**
     * Dernier indice i tel que dist[i] <= x (recherche dichotomique), -1 si aucun.
     *
     * @param list<float> $dist
     */
    private function lastIndexAtOrBefore(array $dist, float $x): int
    {
        $lo = 0;
        $hi = \count($dist) - 1;
        $res = -1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($dist[$mid] <= $x) {
                $res = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $res;
    }
}
