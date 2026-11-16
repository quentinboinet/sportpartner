<?php

namespace App\Service\Nutrition;

use App\Entity\FuelKind;
use App\Entity\RaceNutritionPlan;
use App\Entity\RaceType;

/**
 * Catalogue et limites du plan nutrition d'un scénario de course. Les produits sont ceux du ravitaillement des
 * activités (FuelCatalog : mêmes clés, mêmes glucides par défaut, mêmes produits personnalisés custom_<n>) : un gel
 * est le même "gel" dans les deux fonctions. Ce plan ajoute une CATÉGORIE à chaque produit, utilisée par le calcul
 * auto (RaceNutritionCalculator) : gel (gels, purées), solid (pâtes de fruit, gommes, barres) ou drink (boissons).
 *
 * Réglages (bornes incluses, validées par RaceNutritionPayloadValidator) :
 *  - glucides 0-150 g/h ; hydratation 0-1500 ml/h par pas de 50 ;
 *  - répartition boisson / gels / solide en % entiers, somme = 100 ;
 *  - flasque 100-1500 ml par pas de 50.
 *
 * @phpstan-type Product array{key: string, kind: string, cat: string, label: ?string, carbs: float}
 * @phpstan-type Settings array{carbsPerHour: int, fluidPerHour: int, mixDrink: int, mixGel: int, mixSolid: int, solidEarly: bool, flaskMl: int, gel: ?string, solid: ?string, drink: ?string}
 */
final class RaceNutritionCatalog
{
    public const CAT_GEL = 'gel';
    public const CAT_SOLID = 'solid';
    public const CAT_DRINK = 'drink';

    public const MAX_CARBS_PER_HOUR = 150;
    public const MAX_FLUID_PER_HOUR = 1500;
    public const FLUID_PER_HOUR_MULTIPLE = 50;
    public const MIN_FLASK_ML = 100;
    public const MAX_FLASK_ML = 1500;
    public const FLASK_MULTIPLE = 50;

    /** Contenances proposées dans le choix de la flasque (ml). */
    public const FLASK_CHOICES = [150, 250, 350, 500, 600, 750, 1000];

    /** Eau plate : boisson du catalogue, toujours présente, utilisée pour le volume sans glucides. */
    public const WATER_KEY = 'water';

    /** Catégorie des produits du catalogue (FuelCatalog). */
    private const BUILT_IN_CATEGORIES = [
        'gel' => self::CAT_GEL,
        'puree' => self::CAT_GEL,
        'fruit_paste' => self::CAT_SOLID,
        'gummies' => self::CAT_SOLID,
        'bar' => self::CAT_SOLID,
        'water' => self::CAT_DRINK,
        'isotonic' => self::CAT_DRINK,
        'energy_drink' => self::CAT_DRINK,
    ];

    private function __construct()
    {
    }

    /** Catégorie d'un produit du catalogue (null pour une autre clé). */
    public static function builtInCategory(string $key): ?string
    {
        return self::BUILT_IN_CATEGORIES[$key] ?? null;
    }

    /** Catégories possibles d'un produit d'un type : gel ou solid (aliment), drink (boisson). */
    public static function categoryMatchesKind(string $cat, FuelKind $kind): bool
    {
        return FuelKind::Drink === $kind ? self::CAT_DRINK === $cat : \in_array($cat, [self::CAT_GEL, self::CAT_SOLID], true);
    }

    /**
     * Réglages par défaut d'un scénario sans plan enregistré, selon le type de course :
     * 70 g/h, 500 ml/h, flasque 500 ml, produits auto gel / barre / isotonique ;
     * route : boisson 30 %, gels 70 %, solide 0 % ; trail : 30 / 40 / 30 avec le solide en début de course ;
     * type inconnu : 30 / 40 / 30 sans décalage.
     *
     * @return Settings
     */
    public static function defaultSettings(?RaceType $type): array
    {
        [$gel, $solid, $early] = match ($type) {
            RaceType::Road => [70, 0, false],
            RaceType::Trail => [40, 30, true],
            null => [40, 30, false],
        };

        return [
            'carbsPerHour' => 70,
            'fluidPerHour' => 500,
            'mixDrink' => 30,
            'mixGel' => $gel,
            'mixSolid' => $solid,
            'solidEarly' => $early,
            'flaskMl' => 500,
            'gel' => 'gel',
            'solid' => 'bar',
            'drink' => 'isotonic',
        ];
    }

    /** @return Settings */
    public static function settingsOf(RaceNutritionPlan $plan): array
    {
        return [
            'carbsPerHour' => $plan->getCarbsPerHour(),
            'fluidPerHour' => $plan->getFluidPerHour(),
            'mixDrink' => $plan->getMixDrink(),
            'mixGel' => $plan->getMixGel(),
            'mixSolid' => $plan->getMixSolid(),
            'solidEarly' => $plan->isSolidEarly(),
            'flaskMl' => $plan->getFlaskMl(),
            'gel' => $plan->getGelKey(),
            'solid' => $plan->getSolidKey(),
            'drink' => $plan->getDrinkKey(),
        ];
    }

    /**
     * Produits d'un plan : chaque produit du catalogue (glucides enregistrés dans le plan, sinon la dernière valeur
     * enregistrée par l'utilisateur dans le ravitaillement de ses activités, sinon le défaut du catalogue : même
     * règle qu'ActivityFuelViewBuilder), puis les produits personnalisés du plan par numéro. Ordre : aliments du
     * catalogue, aliments personnalisés, boissons du catalogue, boissons personnalisées.
     *
     * @param list<Product>        $stored     produits enregistrés dans le plan ([] sans plan)
     * @param array<string, float> $remembered glucides retenus pour l'utilisateur, par clé du catalogue
     *
     * @return list<Product>
     */
    public static function products(array $stored, array $remembered): array
    {
        $byKey = [];
        foreach ($stored as $product) {
            $byKey[$product['key']] = $product;
        }

        $products = [];
        foreach ([FuelKind::Food, FuelKind::Drink] as $kind) {
            foreach (FuelCatalog::builtInKeys($kind) as $key) {
                $carbs = isset($byKey[$key]) ? (float) $byKey[$key]['carbs'] : ($remembered[$key] ?? FuelCatalog::defaultCarbs($key));
                $products[] = ['key' => $key, 'kind' => $kind->value, 'cat' => (string) self::builtInCategory($key), 'label' => null, 'carbs' => round($carbs, 1)];
            }
            $custom = array_values(array_filter($stored, static fn (array $p): bool => FuelCatalog::isCustomKey($p['key']) && $p['kind'] === $kind->value));
            usort($custom, static fn (array $a, array $b): int => FuelCatalog::customNumber($a['key']) <=> FuelCatalog::customNumber($b['key']));
            foreach ($custom as $product) {
                $products[] = ['key' => $product['key'], 'kind' => $product['kind'], 'cat' => $product['cat'], 'label' => $product['label'], 'carbs' => round((float) $product['carbs'], 1)];
            }
        }

        return $products;
    }

    /** Glucides d'un produit en dixièmes de gramme (valeur déjà au dixième : pas d'arrondi ambigu). */
    public static function tenths(float $carbs): int
    {
        return (int) round($carbs * 10);
    }

    /**
     * Produits au format du calcul (RaceNutritionCalculator) : glucides en dixièmes de gramme.
     *
     * @param list<Product> $products
     *
     * @return list<array{key: string, kind: string, cat: string, tenths: int}>
     */
    public static function calculatorProducts(array $products): array
    {
        return array_map(static fn (array $p): array => [
            'key' => $p['key'],
            'kind' => $p['kind'],
            'cat' => $p['cat'],
            'tenths' => self::tenths($p['carbs']),
        ], $products);
    }

    /**
     * Limites transmises au JS (mêmes valeurs que la validation serveur) et icônes des produits.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $icons = [];
        foreach (FuelCatalog::builtInKeys() as $key) {
            $icons[$key] = FuelCatalog::icon($key, FuelCatalog::kindOf($key) ?? FuelKind::Food);
        }

        return [
            'icons' => $icons,
            'iconFood' => FuelCatalog::icon('', FuelKind::Food),
            'iconDrink' => FuelCatalog::icon('', FuelKind::Drink),
            'maxCarbsPerHour' => self::MAX_CARBS_PER_HOUR,
            'maxFluidPerHour' => self::MAX_FLUID_PER_HOUR,
            'fluidMultiple' => self::FLUID_PER_HOUR_MULTIPLE,
            'maxFoodUnits' => FuelCatalog::MAX_FOOD_UNITS,
            'maxDrinkMl' => FuelCatalog::MAX_DRINK_ML,
            'drinkMultiple' => FuelCatalog::DRINK_ML_MULTIPLE,
            'drinkStep' => FuelCatalog::DRINK_STEP_ML,
            'maxCarbs' => FuelCatalog::MAX_CARBS,
            'maxCustom' => FuelCatalog::MAX_CUSTOM_PER_KIND,
            'labelMax' => FuelCatalog::LABEL_MAX_LENGTH,
            'minFlask' => self::MIN_FLASK_ML,
            'maxFlask' => self::MAX_FLASK_ML,
            'flaskChoices' => self::FLASK_CHOICES,
            'tolerancePct' => RaceNutritionCalculator::STATUS_TOLERANCE_PCT,
        ];
    }
}
