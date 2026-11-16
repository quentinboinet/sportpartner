<?php

namespace App\Service\Nutrition;

/**
 * Calcul pur (sans base de données) du plan nutrition d'un scénario de course : ce qui est prévu à chaque point et
 * sur chaque tronçon, auto ou manuel, et la liste « à emporter ». Source de référence : rendu serveur de la page plan,
 * fiche imprimable, bracelet, page partagée. Miroir JS EXACT : assets/controllers/race_nutrition_calculator.js
 * (compute(), carryList(), mêmes règles, mêmes opérations entières) ; toute règle se modifie des deux côtés.
 *
 * Plan = points et tronçons dans l'ordre de la course : départ, tronçon 1, repère 1, tronçon 2, ..., tronçon n + 1
 * (jusqu'à l'arrivée). Un tronçon est identifié par son point d'ARRIVÉE (clé "s:<checkpointId>" ou "s:finish"), un
 * point par "p:start" ou "p:<checkpointId>" ; pas de point à l'arrivée (la course est finie).
 *
 * Unités : glucides en CENTIÈMES de gramme (entiers), produits en dixièmes de gramme par unité (aliment) ou pour
 * 500 ml (boisson) ; quantités en unités ou en ml (multiple de 50). Glucides d'une ligne : même règle
 * qu'ActivityFuelCalculator::itemCarbsHundredths() (aliment : qté x dixièmes x 10 ; boisson : arrondi de
 * ml x dixièmes x 10 / 500, exact pour un multiple de 50 ml). Arrondis : roundDiv() (au plus proche, demi vers le
 * haut, sur des entiers >= 0) ; jamais de round() de PHP ni de flottant.
 *
 * Temps : durée EN MOUVEMENT de chaque tronçon (arrêts aux ravitos exclus : manger au ravito est un apport du POINT) ;
 * M(i) = temps en mouvement cumulé à la fin du tronçon i, MT = total. Sans durée connue pour un tronçon (pas de temps
 * visé), ou MT = 0 : pas de calcul auto ("ready" faux) ; les lignes manuelles restent saisissables et comptées.
 *
 * Calcul auto (lignes sans saisie manuelle), dans l'ordre de la course, par DIFFUSION D'ERREUR : à la fin de chaque
 * tronçon auto, le cumul prévu (manuel ET auto, à sa valeur réelle) rattrape la cible x temps cumulé. Pour le
 * tronçon i, de a = M(i - 1) à b = M(i), avec P = cumuls de tout ce qui précède (points compris) :
 *  1. volume : cible = ml/h x b / 3600 ; V = déficit arrondi au pas de 250 ml (FLUID_STEP_ML), plafonné ;
 *  2. boisson glucidique (produit "boisson" du réglage, glucides > 0, part boisson > 0) : sa part des glucides
 *     visée est R_L = min(g/h x part boisson, ml/h x glucides de la boisson / 500) (la boisson ne peut pas dépasser le
 *     volume visé) ; D = (cible cumulée R_L - glucides déjà bus) en ml, au pas de 250 ml, entre 0 et V ;
 *     le reste du volume est de l'eau (W = V - D) ;
 *  3. aliments : déficit total = cible g/h x b - P - glucides bus sur le tronçon ; plafonné (point 5) ;
 *     - gels et solide utilisés (part > 0 et produit choisi, glucides > 0) : le solide suit sa propre cible cumulée,
 *       part solide des aliments = S / (G + S) ; avec « solide plutôt en début de course », la part instantanée vaut
 *       s0 + min(S, G) / (G + S) x (1 - 2x) (x = temps en mouvement écoulé / MT : le double de s0 au départ si
 *       S <= G, rien à la fin ; moyenne s0 sur la course), d'où la part moyenne sur [0, b] :
 *       sigma(b) = (S x MT + min(S, G) x (MT - b)) / ((G + S) x MT), en millionièmes ; cible solide cumulée =
 *       cible aliments(b) x sigma(b), avec cible aliments = (g/h - R_L) x b / 3600 ; unités de solide = arrondi
 *       de min(déficit solide, déficit aliments) / glucides du produit ; puis les gels complètent le déficit ;
 *     - un seul des deux utilisé : il prend tout le déficit aliments ; aucun : pas d'aliment auto ;
 *     - part gels > 0 mais pas de gel choisi : le solide prend tout, et inversement ;
 *  4. aux points d'assistance ou de ravitaillement officiel, les unités de SOLIDE prévues pour le tronçon qui en
 *     part (auto) sont mangées pendant l'arrêt : elles apparaissent sur le point (si le point est auto), pas sur le
 *     tronçon. Aucun autre apport auto aux points (départ compris) ;
 *  5. plafonds STRICTS par tronçon auto (l'arrondi au plus proche est ramené sous le plafond, au pas inférieur) :
 *     plafond des glucides = max(150 % de la cible du tronçon, cible + plus grosse unité d'aliment utilisée), et les
 *     aliments <= max(plafond - glucides bus sur le tronçon, plus grosse unité) ; plafond du volume = max(150 % de la
 *     cible du tronçon, cible + 250 ml). Le terme "cible + une unité" garantit qu'en régime établi la moyenne peut
 *     toujours atteindre la cible (sinon un tronçon de 1,2 unité n'aurait jamais qu'une unité) ; le plafond étale un
 *     rattrapage (après une ligne manuelle vide, par exemple) sur plusieurs tronçons. Un tronçon très court (5 min)
 *     ne reçoit en général rien : sa part est reportée sur le tronçon suivant (diffusion d'erreur).
 * Unités entières (jamais de demi-gel), boissons au pas de 250 ml ; les lignes manuelles sont gardées telles quelles.
 *
 * Débits : g/h = centièmes x 36 / secondes (comme ActivityFuelCalculator), ml/h = ml x 3600 / secondes, arrondis.
 * Débit d'un tronçon = (point de départ du tronçon + tronçon) / durée du tronçon ; total = tout / MT.
 * Statut (FuelTargetStatus) : below / within / above si l'écart avec la cible dépasse STATUS_TOLERANCE_PCT % de la
 * cible (bornes incluses : within), none sans durée, ou pour un tronçon de moins de 20 min (trop court pour juger).
 *
 * @phpstan-type Line array{key: string, qty: int}
 * @phpstan-type CalcProduct array{key: string, kind: string, cat: string, tenths: int}
 * @phpstan-type CalcSettings array{carbsPerHour: int, fluidPerHour: int, mixDrink: int, mixGel: int, mixSolid: int, solidEarly: bool, gel: ?string, solid: ?string, drink: ?string}
 * @phpstan-type CalcPoint array{anchor: string, type: string, assistance: bool}
 * @phpstan-type CalcSegment array{anchor: string, seconds: ?int}
 * @phpstan-type Input array{settings: CalcSettings, products: list<CalcProduct>, points: list<CalcPoint>, segments: list<CalcSegment>, manual: array<string, list<Line>>}
 * @phpstan-type Row array{key: string, place: string, anchor: string, manual: bool, items: list<Line>, carbsH: int, carbs: int, fluidMl: int, seconds: ?int, carbsPerHour: ?int, fluidPerHour: ?int, status: string, fluidStatus: string}
 * @phpstan-type Totals array{carbsH: int, carbs: int, fluidMl: int, movingSeconds: ?int, carbsPerHour: ?int, fluidPerHour: ?int, status: string, fluidStatus: string, products: list<Line>}
 * @phpstan-type Result array{ready: bool, rows: list<Row>, totals: Totals}
 * @phpstan-type Fill array{key: string, ml: int, flasks: int}
 * @phpstan-type Stop array{anchor: string, type: string, assistance: bool, eatHere: list<Line>, carry: ?list<Line>, fill: ?list<Fill>, flasks: int}
 */
final class RaceNutritionCalculator
{
    /** Plafond d'un tronçon auto : 150 % de sa cible (glucides et volume). */
    public const CAP_PCT = 150;

    /** Écart toléré avec la cible pour le statut "within" (%). */
    public const STATUS_TOLERANCE_PCT = 20;

    /** Un tronçon plus court n'a pas de statut (trop court pour juger un débit). */
    public const STATUS_MIN_SECONDS = 1200;

    /** Pas du volume auto (ml) : celui des boutons +/- des boissons (FuelCatalog::DRINK_STEP_ML). */
    public const FLUID_STEP_ML = FuelCatalog::DRINK_STEP_ML;

    /** Échelle de la part de solide sigma (millionièmes). */
    public const SIGMA_SCALE = 1000000;

    public const PLACE_POINT = 'point';
    public const PLACE_SEGMENT = 'segment';
    public const TYPE_START = 'start';
    public const TYPE_AID_STATION = 'aid_station';

    public const STATUS_NONE = 'none';
    public const STATUS_BELOW = 'below';
    public const STATUS_WITHIN = 'within';
    public const STATUS_ABOVE = 'above';

    /**
     * @param Input $input points : départ puis repères (n + 1) ; segments : n + 1 tronçons, le i-ème part de
     *                     points[i] ; manual : clé de ligne => lignes saisies (liste vide = "rien ici")
     *
     * @return Result rows : départ, tronçon 1, repère 1, ..., dernier tronçon
     */
    public function compute(array $input): array
    {
        $s = $input['settings'];
        $products = [];
        foreach ($input['products'] as $product) {
            $products[$product['key']] = $product;
        }
        $points = $input['points'];
        $segments = $input['segments'];
        $manual = $input['manual'];
        $rate = $s['carbsPerHour'];
        $fluid = $s['fluidPerHour'];

        $ready = [] !== $segments;
        $total = 0;
        foreach ($segments as $segment) {
            if (null === $segment['seconds']) {
                $ready = false;
            } else {
                $total += max(0, $segment['seconds']);
            }
        }
        $ready = $ready && $total > 0;

        $gel = self::usable($products, $s['gel'], RaceNutritionCatalog::CAT_GEL, $s['mixGel']);
        $solid = self::usable($products, $s['solid'], RaceNutritionCatalog::CAT_SOLID, $s['mixSolid']);
        $drink = self::usable($products, $s['drink'], RaceNutritionCatalog::CAT_DRINK, $s['mixDrink']);
        $water = $products[RaceNutritionCatalog::WATER_KEY] ?? null;
        $filler = $gel ?? $solid;
        $tracked = null !== $gel ? $solid : null;
        $maxUnit = max(null !== $gel ? $gel['tenths'] * 10 : 0, null !== $solid ? $solid['tenths'] * 10 : 0);
        // Glucides par heure x 50 (centièmes) : boisson (bornée par le volume visé), aliments = le reste
        $liquid50 = null !== $drink ? min($rate * $s['mixDrink'] * 50, $fluid * $drink['tenths']) : 0;
        $food50 = $rate * 5000 - $liquid50;
        $ctx = [
            'rate' => $rate, 'fluid' => $fluid, 'total' => $total, 'early' => $s['solidEarly'],
            'mixGel' => $s['mixGel'], 'mixSolid' => $s['mixSolid'], 'liquid50' => $liquid50, 'food50' => $food50,
            'drink' => $drink, 'water' => $water, 'filler' => $filler, 'tracked' => $tracked, 'maxUnit' => $maxUnit,
        ];

        $sum = ['carbs' => 0, 'fluid' => 0, 'liquid' => 0, 'solid' => 0];
        /** @var list<array{key: string, place: string, anchor: string, manual: bool, items: list<Line>, seconds: ?int}> $rows */
        $rows = [];

        $startKey = 'p:'.($points[0]['anchor'] ?? 'start');
        $startItems = $manual[$startKey] ?? [];
        $rows[] = ['key' => $startKey, 'place' => self::PLACE_POINT, 'anchor' => $points[0]['anchor'] ?? 'start', 'manual' => \array_key_exists($startKey, $manual), 'items' => $startItems, 'seconds' => null];
        self::add($sum, $startItems, $products);
        $pointRow = 0;

        $elapsed = 0;
        $n = \count($segments) - 1;
        foreach ($segments as $i => $segment) {
            $seconds = null !== $segment['seconds'] ? max(0, $segment['seconds']) : null;
            $from = $elapsed;
            $elapsed += $seconds ?? 0;
            $key = 's:'.$segment['anchor'];
            $isManual = \array_key_exists($key, $manual);
            $items = [];
            if ($isManual) {
                $items = $manual[$key];
            } elseif ($ready) {
                $items = $this->auto($ctx, $sum, $from, $elapsed);
                // Solide mangé au point de départ du tronçon (assistance ou ravito officiel resté en auto)
                $start = $points[$i] ?? null;
                if ($i > 0 && null !== $start && !$rows[$pointRow]['manual'] && self::eligible($start)) {
                    $moved = [];
                    $kept = [];
                    foreach ($items as $line) {
                        if (RaceNutritionCatalog::CAT_SOLID === ($products[$line['key']]['cat'] ?? null)) {
                            $moved[] = $line;
                        } else {
                            $kept[] = $line;
                        }
                    }
                    $rows[$pointRow]['items'] = $moved;
                    $items = $kept;
                    self::add($sum, $moved, $products);
                }
            }
            self::add($sum, $items, $products);
            $rows[] = ['key' => $key, 'place' => self::PLACE_SEGMENT, 'anchor' => $segment['anchor'], 'manual' => $isManual, 'items' => $items, 'seconds' => $seconds];

            if ($i < $n) {
                $point = $points[$i + 1];
                $pKey = 'p:'.$point['anchor'];
                $pManual = \array_key_exists($pKey, $manual);
                $pItems = $pManual ? $manual[$pKey] : [];
                self::add($sum, $pItems, $products);
                $rows[] = ['key' => $pKey, 'place' => self::PLACE_POINT, 'anchor' => $point['anchor'], 'manual' => $pManual, 'items' => $pItems, 'seconds' => null];
                $pointRow = \count($rows) - 1;
            }
        }

        return $this->finish($rows, $products, $rate, $fluid, $ready ? $total : null, $ready);
    }

    /**
     * Liste « à emporter » d'un plan calculé (compute()). Arrêts listés, dans l'ordre : le départ, puis chaque repère
     * qui est un ravitaillement officiel et / ou un point d'assistance.
     *  - eatHere : apports prévus AU point (repère : sur place, fournis par le ravito ou par l'assistance) ;
     *  - carry (départ et points d'assistance, sinon null) : aliments à emporter d'ici jusqu'au prochain point
     *    d'assistance : ceux des tronçons et des points simples (non ravito) entre les deux, plus ceux mangés à ce point
     *    s'il est le départ ou un point d'assistance (pris dans le sac) ; ce qui est prévu à un ravitaillement
     *    officiel sans assistance est compté comme pris sur place ;
     *  - fill (départ, ravitos officiels et points d'assistance, sinon null) : boissons à emporter jusqu'au prochain
     *    point où l'on peut remplir, par produit : ml et flasques = arrondi au-dessus de ml / contenance d'une flasque ;
     *    les boissons bues à un point de remplissage le sont sur place (non emportées) ;
     *  - flasks : total des flasques à remplir ici.
     * Listes triées dans l'ordre des produits du plan ; quantités nulles retirées.
     *
     * @param Input  $input
     * @param Result $result
     *
     * @return list<Stop>
     */
    public function carryList(array $input, array $result, int $flaskMl): array
    {
        $order = [];
        $kinds = [];
        foreach ($input['products'] as $i => $product) {
            $order[$product['key']] = $i;
            $kinds[$product['key']] = $product['kind'];
        }
        $pointsByAnchor = [];
        foreach ($input['points'] as $point) {
            $pointsByAnchor[$point['anchor']] = $point;
        }
        $flask = max(1, $flaskMl);

        /** @var list<array{anchor: string, type: string, assistance: bool, eatHere: array<string, int>, carry: ?array<string, int>, fill: ?array<string, int>}> $stops */
        $stops = [];
        $foodStop = -1;
        $drinkStop = -1;
        foreach ($result['rows'] as $row) {
            if (self::PLACE_POINT === $row['place']) {
                $point = $pointsByAnchor[$row['anchor']] ?? ['anchor' => $row['anchor'], 'type' => 'point', 'assistance' => false];
                $isStart = self::TYPE_START === $point['type'];
                $isAid = self::TYPE_AID_STATION === $point['type'];
                $food = $isStart || $point['assistance'];
                $refill = $food || $isAid;
                if ($refill) {
                    $stops[] = ['anchor' => $row['anchor'], 'type' => $point['type'], 'assistance' => $point['assistance'], 'eatHere' => [], 'carry' => $food ? [] : null, 'fill' => []];
                    $index = \count($stops) - 1;
                    $drinkStop = $index;
                    if ($food) {
                        $foodStop = $index;
                    }
                    foreach ($row['items'] as $line) {
                        $stops[$index]['eatHere'][$line['key']] = ($stops[$index]['eatHere'][$line['key']] ?? 0) + $line['qty'];
                        // Aliments mangés au départ ou à l'assistance : pris dans ses propres produits
                        if ($food && 'drink' !== ($kinds[$line['key']] ?? 'food')) {
                            $stops[$index]['carry'][$line['key']] = ($stops[$index]['carry'][$line['key']] ?? 0) + $line['qty'];
                        }
                    }
                    continue;
                }
            }
            // Tronçon, ou point simple : tout est emporté depuis les derniers arrêts
            foreach ($row['items'] as $line) {
                if ('drink' === ($kinds[$line['key']] ?? 'food')) {
                    if ($drinkStop >= 0) {
                        $stops[$drinkStop]['fill'][$line['key']] = ($stops[$drinkStop]['fill'][$line['key']] ?? 0) + $line['qty'];
                    }
                } elseif ($foodStop >= 0) {
                    $stops[$foodStop]['carry'][$line['key']] = ($stops[$foodStop]['carry'][$line['key']] ?? 0) + $line['qty'];
                }
            }
        }

        $list = static function (array $quantities) use ($order): array {
            $keys = array_keys(array_filter($quantities, static fn (int $q): bool => $q > 0));
            usort($keys, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

            return array_map(static fn (string $key): array => ['key' => $key, 'qty' => $quantities[$key]], $keys);
        };

        $out = [];
        foreach ($stops as $stop) {
            $fill = null;
            $flasks = 0;
            if (null !== $stop['fill']) {
                $fill = [];
                foreach ($list($stop['fill']) as $line) {
                    $count = intdiv($line['qty'] + $flask - 1, $flask);
                    $flasks += $count;
                    $fill[] = ['key' => $line['key'], 'ml' => $line['qty'], 'flasks' => $count];
                }
            }
            $out[] = [
                'anchor' => $stop['anchor'],
                'type' => $stop['type'],
                'assistance' => $stop['assistance'],
                'eatHere' => $list($stop['eatHere']),
                'carry' => null !== $stop['carry'] ? $list($stop['carry']) : null,
                'fill' => $fill,
                'flasks' => $flasks,
            ];
        }

        return $out;
    }

    /** Statut d'un débit par rapport à la cible (voir le docblock de la classe). */
    public static function status(?int $rate, int $target): string
    {
        if (null === $rate) {
            return self::STATUS_NONE;
        }
        $tolerance = ActivityFuelCalculator::roundDiv($target * self::STATUS_TOLERANCE_PCT, 100);
        if ($rate < $target - $tolerance) {
            return self::STATUS_BELOW;
        }

        return $rate > $target + $tolerance ? self::STATUS_ABOVE : self::STATUS_WITHIN;
    }

    /**
     * Glucides d'une ligne en centièmes de gramme (règle d'ActivityFuelCalculator::itemCarbsHundredths()).
     *
     * @param CalcProduct|null $product
     */
    public static function lineHundredths(?array $product, int $qty): int
    {
        if (null === $product || $qty <= 0) {
            return 0;
        }

        return 'drink' === $product['kind']
            ? ActivityFuelCalculator::roundDiv($qty * $product['tenths'] * 10, FuelCatalog::DRINK_REFERENCE_ML)
            : $qty * $product['tenths'] * 10;
    }

    /**
     * Apports auto d'un tronçon (voir le docblock de la classe, étapes 1 à 5).
     *
     * @param array{rate: int, fluid: int, total: int, early: bool, mixGel: int, mixSolid: int, liquid50: int, food50: int, drink: ?CalcProduct, water: ?CalcProduct, filler: ?CalcProduct, tracked: ?CalcProduct, maxUnit: int} $c
     * @param array{carbs: int, fluid: int, liquid: int, solid: int} $sum
     *
     * @return list<Line>
     */
    private function auto(array $c, array $sum, int $a, int $b): array
    {
        $dt = $b - $a;
        if ($dt <= 0) {
            return [];
        }

        // 1. Volume (échelle : ml x 3600 x 100), au pas le plus proche, jamais au-delà du plafond
        $volume = 0;
        if ($c['fluid'] > 0) {
            $deficit = ($c['fluid'] * $b - 3600 * $sum['fluid']) * 100;
            $step = 3600 * self::FLUID_STEP_ML * 100;
            $cap = max($c['fluid'] * $dt * self::CAP_PCT, $c['fluid'] * $dt * 100 + $step);
            if ($deficit > 0) {
                $volume = min(ActivityFuelCalculator::roundDiv($deficit, $step), intdiv($cap, $step)) * self::FLUID_STEP_ML;
            }
        }

        // 2. Boisson glucidique, puis eau pour le reste du volume
        $drinkMl = 0;
        if (null !== $c['drink'] && $volume > 0) {
            $need = ActivityFuelCalculator::roundDiv($c['liquid50'] * $b, 180000) - $sum['liquid'];
            if ($need > 0) {
                $drinkMl = min($volume, ActivityFuelCalculator::roundDiv($need * 50, $c['drink']['tenths'] * self::FLUID_STEP_ML) * self::FLUID_STEP_ML);
            }
        }
        $waterMl = null !== $c['water'] ? $volume - $drinkMl : 0;
        $drinkCarbs = self::lineHundredths($c['drink'], $drinkMl) + self::lineHundredths($c['water'], $waterMl);

        // 3. Aliments : déficit total, à l'unité la plus proche, jamais au-delà du plafond (5)
        $fillerUnits = 0;
        $solidUnits = 0;
        if (null !== $c['filler']) {
            $targetB = ActivityFuelCalculator::roundDiv($c['rate'] * 100 * $b, 3600);
            $targetA = ActivityFuelCalculator::roundDiv($c['rate'] * 100 * $a, 3600);
            $increment = $targetB - $targetA;
            $cap = max(ActivityFuelCalculator::roundDiv($increment * self::CAP_PCT, 100), $increment + $c['maxUnit']);
            $room = max($cap - $drinkCarbs, $c['maxUnit']);
            $deficit = $targetB - $sum['carbs'] - $drinkCarbs;
            if ($deficit > 0) {
                $tracked = $c['tracked'];
                $rest = $deficit;
                if (null !== $tracked) {
                    $unit = $tracked['tenths'] * 10;
                    $foodTarget = ActivityFuelCalculator::roundDiv($c['food50'] * $b, 180000);
                    $sigma = ActivityFuelCalculator::roundDiv(
                        self::SIGMA_SCALE * ($c['mixSolid'] * $c['total'] + ($c['early'] ? min($c['mixSolid'], $c['mixGel']) : 0) * ($c['total'] - $b)),
                        ($c['mixGel'] + $c['mixSolid']) * $c['total'],
                    );
                    $solidNeed = ActivityFuelCalculator::roundDiv($foodTarget * $sigma, self::SIGMA_SCALE) - $sum['solid'];
                    if ($solidNeed > 0) {
                        $solidUnits = min(ActivityFuelCalculator::roundDiv(min($solidNeed, $deficit), $unit), intdiv($room, $unit));
                    }
                    $rest = $deficit - $solidUnits * $unit;
                    $room -= $solidUnits * $unit;
                }
                if ($rest > 0) {
                    $unit = $c['filler']['tenths'] * 10;
                    $fillerUnits = min(ActivityFuelCalculator::roundDiv($rest, $unit), intdiv($room, $unit));
                }
            }
        }

        $items = [];
        if ($fillerUnits > 0 && null !== $c['filler']) {
            $items[] = ['key' => $c['filler']['key'], 'qty' => $fillerUnits];
        }
        if ($solidUnits > 0 && null !== $c['tracked']) {
            $items[] = ['key' => $c['tracked']['key'], 'qty' => $solidUnits];
        }
        if ($drinkMl > 0 && null !== $c['drink']) {
            $items[] = ['key' => $c['drink']['key'], 'qty' => $drinkMl];
        }
        if ($waterMl > 0 && null !== $c['water']) {
            $items[] = ['key' => $c['water']['key'], 'qty' => $waterMl];
        }

        return $items;
    }

    /**
     * Totaux des lignes, débits et statuts.
     *
     * @param list<array{key: string, place: string, anchor: string, manual: bool, items: list<Line>, seconds: ?int}> $rows
     * @param array<string, CalcProduct>                                                                         $products
     *
     * @return Result
     */
    private function finish(array $rows, array $products, int $rate, int $fluid, ?int $moving, bool $ready): array
    {
        $out = [];
        $totalCarbs = 0;
        $totalFluid = 0;
        $byKey = [];
        $previousCarbs = 0;
        $previousFluid = 0;
        foreach ($rows as $row) {
            $carbs = 0;
            $fluidMl = 0;
            foreach ($row['items'] as $line) {
                $product = $products[$line['key']] ?? null;
                $carbs += self::lineHundredths($product, $line['qty']);
                if (null !== $product && 'drink' === $product['kind']) {
                    $fluidMl += max(0, $line['qty']);
                }
                $byKey[$line['key']] = ($byKey[$line['key']] ?? 0) + max(0, $line['qty']);
            }
            $totalCarbs += $carbs;
            $totalFluid += $fluidMl;

            $carbsRate = null;
            $fluidRate = null;
            $status = self::STATUS_NONE;
            $fluidStatus = self::STATUS_NONE;
            if (self::PLACE_SEGMENT === $row['place'] && null !== $row['seconds'] && $row['seconds'] > 0) {
                // Débit du tronçon : ce qui est pris à son point de départ compte avec lui
                $carbsRate = ActivityFuelCalculator::roundDiv(($previousCarbs + $carbs) * 36, $row['seconds']);
                $fluidRate = ActivityFuelCalculator::roundDiv(($previousFluid + $fluidMl) * 3600, $row['seconds']);
                if ($row['seconds'] >= self::STATUS_MIN_SECONDS) {
                    $status = self::status($carbsRate, $rate);
                    $fluidStatus = self::status($fluidRate, $fluid);
                }
            }
            if (self::PLACE_POINT === $row['place']) {
                $previousCarbs = $carbs;
                $previousFluid = $fluidMl;
            }

            $out[] = [
                'key' => $row['key'],
                'place' => $row['place'],
                'anchor' => $row['anchor'],
                'manual' => $row['manual'],
                'items' => $row['items'],
                'carbsH' => $carbs,
                'carbs' => ActivityFuelCalculator::roundDiv($carbs, 100),
                'fluidMl' => $fluidMl,
                'seconds' => $row['seconds'],
                'carbsPerHour' => $carbsRate,
                'fluidPerHour' => $fluidRate,
                'status' => $status,
                'fluidStatus' => $fluidStatus,
            ];
        }

        $carbsRate = null !== $moving && $moving > 0 ? ActivityFuelCalculator::roundDiv($totalCarbs * 36, $moving) : null;
        $fluidRate = null !== $moving && $moving > 0 ? ActivityFuelCalculator::roundDiv($totalFluid * 3600, $moving) : null;
        $productTotals = [];
        foreach ($products as $key => $product) {
            if (($byKey[$key] ?? 0) > 0) {
                $productTotals[] = ['key' => (string) $key, 'qty' => $byKey[$key]];
            }
        }

        return [
            'ready' => $ready,
            'rows' => $out,
            'totals' => [
                'carbsH' => $totalCarbs,
                'carbs' => ActivityFuelCalculator::roundDiv($totalCarbs, 100),
                'fluidMl' => $totalFluid,
                'movingSeconds' => $moving,
                'carbsPerHour' => $carbsRate,
                'fluidPerHour' => $fluidRate,
                'status' => self::status($carbsRate, $rate),
                'fluidStatus' => self::status($fluidRate, $fluid),
                'products' => $productTotals,
            ],
        ];
    }

    /**
     * Ajoute des lignes aux cumuls (glucides, volume, glucides bus, glucides de solide).
     *
     * @param array{carbs: int, fluid: int, liquid: int, solid: int} $sum
     * @param list<Line>                                             $items
     * @param array<string, CalcProduct>                             $products
     */
    private static function add(array &$sum, array $items, array $products): void
    {
        foreach ($items as $line) {
            $product = $products[$line['key']] ?? null;
            if (null === $product || $line['qty'] <= 0) {
                continue;
            }
            $carbs = self::lineHundredths($product, $line['qty']);
            $sum['carbs'] += $carbs;
            if ('drink' === $product['kind']) {
                $sum['fluid'] += $line['qty'];
                $sum['liquid'] += $carbs;
            } elseif (RaceNutritionCatalog::CAT_SOLID === $product['cat']) {
                $sum['solid'] += $carbs;
            }
        }
    }

    /**
     * Produit utilisable par le calcul auto : choisi, de la bonne catégorie, avec des glucides, et part > 0.
     *
     * @param array<string, CalcProduct> $products
     *
     * @return CalcProduct|null
     */
    private static function usable(array $products, ?string $key, string $cat, int $share): ?array
    {
        $product = null !== $key ? ($products[$key] ?? null) : null;
        if (null === $product || $product['cat'] !== $cat || $product['tenths'] <= 0 || $share <= 0) {
            return null;
        }

        return $product;
    }

    /** @param CalcPoint $point */
    private static function eligible(array $point): bool
    {
        return self::TYPE_AID_STATION === $point['type'] || $point['assistance'];
    }
}
