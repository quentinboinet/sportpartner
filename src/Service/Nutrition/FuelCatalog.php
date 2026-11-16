<?php

namespace App\Service\Nutrition;

use App\Entity\FuelKind;

/**
 * Catalogue du ravitaillement d'une activité : apports proposés (avec leurs glucides par défaut),
 * clés des apports personnalisés et limites de saisie, partagées par la validation (serveur),
 * l'affichage (Twig) et le JS (valeurs transmises par le partial activities/_fuel.html.twig).
 */
final class FuelCatalog
{
    /** Sports (slugs) dont la page de détail affiche le ravitaillement ; une activité sans sport compte comme "other". */
    public const SPORT_SLUGS = ['run', 'trail', 'ride', 'walk', 'hike', 'other'];

    /** Volume de référence des glucides d'une boisson : grammes pour 500 ml. */
    public const DRINK_REFERENCE_ML = 500;

    /** Aliments : 0 à 100 unités par ligne. */
    public const MAX_FOOD_UNITS = 100;

    /** Boissons : 0 à 20 000 ml par ligne, par multiples de 50 ml. */
    public const MAX_DRINK_ML = 20000;
    public const DRINK_ML_MULTIPLE = 50;

    /** Pas des boutons +/- des boissons (ml). */
    public const DRINK_STEP_ML = 250;

    /** Glucides par unité (ou pour 500 ml) : 0 à 200 g, arrondis au dixième. */
    public const MAX_CARBS = 200;

    /** Apports personnalisés : au plus 10 aliments et 10 boissons par activité, nom de 1 à 60 caractères. */
    public const MAX_CUSTOM_PER_KIND = 10;
    public const LABEL_MAX_LENGTH = 60;

    /** Clé d'un apport personnalisé : custom_1 à custom_999, unique dans l'activité (aliments et boissons confondus). */
    public const CUSTOM_KEY_PATTERN = '/^custom_([1-9][0-9]{0,2})$/';

    /**
     * Apports proposés, dans l'ordre d'affichage : [type, glucides par défaut, icône Bootstrap Icons].
     * Les noms sont traduits par activity_fuel.items.<clé>.name.
     *
     * @var array<string, array{0: FuelKind, 1: float, 2: string}>
     */
    private const BUILT_IN = [
        'gel'          => [FuelKind::Food, 25.0, 'bi-lightning-charge-fill'],
        'puree'        => [FuelKind::Food, 22.0, 'bi-cup-fill'],
        'fruit_paste'  => [FuelKind::Food, 18.0, 'bi-gem'],
        'gummies'      => [FuelKind::Food, 24.0, 'bi-capsule'],
        'bar'          => [FuelKind::Food, 35.0, 'bi-cookie'],
        'water'        => [FuelKind::Drink, 0.0, 'bi-droplet-fill'],
        'isotonic'     => [FuelKind::Drink, 30.0, 'bi-droplet-half'],
        'energy_drink' => [FuelKind::Drink, 45.0, 'bi-cup-straw'],
    ];

    private function __construct()
    {
    }

    /**
     * Clés des apports proposés, dans l'ordre d'affichage (d'un seul type si $kind est donné).
     *
     * @return list<string>
     */
    public static function builtInKeys(?FuelKind $kind = null): array
    {
        $keys = [];
        foreach (self::BUILT_IN as $key => [$itemKind]) {
            if ($kind === null || $itemKind === $kind) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public static function isBuiltIn(string $key): bool
    {
        return isset(self::BUILT_IN[$key]);
    }

    /** Type d'un apport proposé (null pour une clé hors catalogue). */
    public static function kindOf(string $key): ?FuelKind
    {
        return self::BUILT_IN[$key][0] ?? null;
    }

    /** Glucides par défaut d'un apport proposé (0 pour une clé hors catalogue). */
    public static function defaultCarbs(string $key): float
    {
        return self::BUILT_IN[$key][1] ?? 0.0;
    }

    /** Icône d'un apport : celle du catalogue, sinon une icône générique par type. */
    public static function icon(string $key, FuelKind $kind): string
    {
        return self::BUILT_IN[$key][2] ?? ($kind === FuelKind::Food ? 'bi-basket' : 'bi-cup');
    }

    public static function isCustomKey(string $key): bool
    {
        return preg_match(self::CUSTOM_KEY_PATTERN, $key) === 1;
    }

    /** Numéro d'un apport personnalisé (custom_12 -> 12), 0 pour une autre clé. */
    public static function customNumber(string $key): int
    {
        return preg_match(self::CUSTOM_KEY_PATTERN, $key, $m) === 1 ? (int) $m[1] : 0;
    }

    public static function supportsSportSlug(?string $slug): bool
    {
        return in_array($slug ?? 'other', self::SPORT_SLUGS, true);
    }

    /**
     * Quantité maximale d'une ligne : unités (aliment) ou ml (boisson).
     */
    public static function maxQuantity(FuelKind $kind): int
    {
        return $kind === FuelKind::Food ? self::MAX_FOOD_UNITS : self::MAX_DRINK_ML;
    }
}
