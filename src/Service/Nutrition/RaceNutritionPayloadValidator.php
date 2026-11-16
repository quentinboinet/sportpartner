<?php

namespace App\Service\Nutrition;

use App\Entity\FuelKind;

/**
 * Validation du JSON envoyé par la section Nutrition du plan de course (POST /courses/{id}/scenarios/{sid}/nutrition,
 * enregistrement automatique : tout l'état du plan du scénario à chaque envoi) :
 *
 *   {"settings": {"carbsPerHour": 70, "fluidPerHour": 500, "mixDrink": 30, "mixGel": 40, "mixSolid": 30,
 *                 "solidEarly": true, "flaskMl": 500, "gel": "gel", "solid": "bar", "drink": "isotonic"},
 *    "products": [{"key": "gel", "carbs": 25},
 *                 {"key": "custom_1", "kind": "food", "cat": "solid", "label": "Banane", "carbs": 27}],
 *    "manual": [{"place": "segment", "anchor": "123", "items": [{"key": "gel", "qty": 2}]},
 *               {"place": "point", "anchor": "start", "items": []}]}
 *
 *  - settings : glucides 0-150 g/h, hydratation 0-1500 ml/h par 50, répartition boisson / gels / solide en % entiers
 *    de somme 100, solidEarly booléen, flasque 100-1500 ml par 50 ; gel / solid / drink : null ou clé d'un produit
 *    de la bonne catégorie (gel, solide, boisson) ;
 *  - products : produits du catalogue FuelCatalog ({key, carbs}, kind / cat facultatifs mais exacts, pas de label ;
 *    absent = glucides par défaut) et produits personnalisés custom_<n> ({key, kind, cat, label, carbs} : nom de 1 à 60
 *    caractères sans caractère de contrôle, catégorie gel ou solid pour un aliment, drink pour une boisson) ;
 *    glucides 0-200 g (par unité, ou pour 500 ml), arrondis au dixième ; clés uniques ; au plus 10 produits
 *    personnalisés par type (mêmes règles que FuelPayloadValidator) ;
 *  - manual : lignes manuelles, place "point" (anchor "start" ou id de repère) ou "segment" (anchor "finish" ou id
 *    de repère : tronçon qui ARRIVE à ce point), une seule par emplacement ; items : au plus 20 lignes {key, qty},
 *    clé d'un produit connu, unique dans la ligne, qty entier 0-100 unités (aliment) ou 0-20 000 ml par multiples de
 *    50 (boisson) ; les quantités nulles sont retirées, une liste vide reste une ligne manuelle ("rien ici").
 * Les repères inconnus ou hors du tableau ne sont pas vérifiés ici : le contrôleur les ignore.
 * La moindre erreur rejette toute la saisie (InvalidFuelPayloadException, réponse 422).
 *
 * @phpstan-import-type Product from RaceNutritionCatalog
 * @phpstan-import-type Settings from RaceNutritionCatalog
 *
 * @phpstan-type ManualRow array{place: string, anchor: string, items: list<array{key: string, qty: int}>}
 * @phpstan-type State array{settings: Settings, products: list<Product>, manual: list<ManualRow>}
 */
final class RaceNutritionPayloadValidator
{
    public const MAX_MANUAL_ROWS = 500;
    public const MAX_LINES_PER_ROW = 20;

    private const SETTINGS_INT = ['carbsPerHour', 'fluidPerHour', 'mixDrink', 'mixGel', 'mixSolid', 'flaskMl'];

    /**
     * @return State
     *
     * @throws InvalidFuelPayloadException
     */
    public function validate(mixed $payload): array
    {
        if (!\is_array($payload) || array_is_list($payload) && [] !== $payload) {
            throw new InvalidFuelPayloadException('Expected an object.');
        }
        foreach (['settings', 'products', 'manual'] as $key) {
            if (!\array_key_exists($key, $payload)) {
                throw new InvalidFuelPayloadException(sprintf('Missing "%s".', $key));
            }
        }

        $products = $this->products($payload['products']);
        $known = [];
        foreach (FuelCatalog::builtInKeys() as $key) {
            $known[$key] = ['kind' => FuelCatalog::kindOf($key)?->value, 'cat' => RaceNutritionCatalog::builtInCategory($key)];
        }
        foreach ($products as $product) {
            $known[$product['key']] = ['kind' => $product['kind'], 'cat' => $product['cat']];
        }

        return [
            'settings' => $this->settings($payload['settings'], $known),
            'products' => $products,
            'manual' => $this->manual($payload['manual'], $known),
        ];
    }

    /**
     * @param array<string, array{kind: ?string, cat: ?string}> $known
     *
     * @return Settings
     */
    private function settings(mixed $raw, array $known): array
    {
        if (!\is_array($raw) || array_is_list($raw)) {
            throw new InvalidFuelPayloadException('settings must be an object.');
        }
        $values = [];
        foreach (self::SETTINGS_INT as $key) {
            if (!\is_int($raw[$key] ?? null)) {
                throw new InvalidFuelPayloadException("settings.$key must be an integer.");
            }
            $values[$key] = $raw[$key];
        }
        if ($values['carbsPerHour'] < 0 || $values['carbsPerHour'] > RaceNutritionCatalog::MAX_CARBS_PER_HOUR) {
            throw new InvalidFuelPayloadException(sprintf('settings.carbsPerHour must be from 0 to %d.', RaceNutritionCatalog::MAX_CARBS_PER_HOUR));
        }
        if ($values['fluidPerHour'] < 0 || $values['fluidPerHour'] > RaceNutritionCatalog::MAX_FLUID_PER_HOUR
            || 0 !== $values['fluidPerHour'] % RaceNutritionCatalog::FLUID_PER_HOUR_MULTIPLE) {
            throw new InvalidFuelPayloadException(sprintf('settings.fluidPerHour must be a multiple of %d from 0 to %d.', RaceNutritionCatalog::FLUID_PER_HOUR_MULTIPLE, RaceNutritionCatalog::MAX_FLUID_PER_HOUR));
        }
        foreach (['mixDrink', 'mixGel', 'mixSolid'] as $key) {
            if ($values[$key] < 0 || $values[$key] > 100) {
                throw new InvalidFuelPayloadException("settings.$key must be from 0 to 100.");
            }
        }
        if (100 !== $values['mixDrink'] + $values['mixGel'] + $values['mixSolid']) {
            throw new InvalidFuelPayloadException('settings mix must sum to 100.');
        }
        if ($values['flaskMl'] < RaceNutritionCatalog::MIN_FLASK_ML || $values['flaskMl'] > RaceNutritionCatalog::MAX_FLASK_ML
            || 0 !== $values['flaskMl'] % RaceNutritionCatalog::FLASK_MULTIPLE) {
            throw new InvalidFuelPayloadException('settings.flaskMl is out of range.');
        }
        if (!\is_bool($raw['solidEarly'] ?? null)) {
            throw new InvalidFuelPayloadException('settings.solidEarly must be a boolean.');
        }

        $choice = static function (string $field, callable $accepts) use ($raw, $known): ?string {
            $key = $raw[$field] ?? null;
            if (null === $key) {
                return null;
            }
            if (!\is_string($key) || !isset($known[$key]) || !$accepts($known[$key])) {
                throw new InvalidFuelPayloadException("settings.$field must be null or a product of the right category.");
            }

            return $key;
        };

        return [
            'carbsPerHour' => $values['carbsPerHour'],
            'fluidPerHour' => $values['fluidPerHour'],
            'mixDrink' => $values['mixDrink'],
            'mixGel' => $values['mixGel'],
            'mixSolid' => $values['mixSolid'],
            'solidEarly' => $raw['solidEarly'],
            'flaskMl' => $values['flaskMl'],
            'gel' => $choice('gel', static fn (array $p): bool => RaceNutritionCatalog::CAT_GEL === $p['cat']),
            'solid' => $choice('solid', static fn (array $p): bool => RaceNutritionCatalog::CAT_SOLID === $p['cat']),
            'drink' => $choice('drink', static fn (array $p): bool => FuelKind::Drink->value === $p['kind']),
        ];
    }

    /** @return list<Product> */
    private function products(mixed $raw): array
    {
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new InvalidFuelPayloadException('products must be a list.');
        }
        $max = \count(FuelCatalog::builtInKeys()) + 2 * FuelCatalog::MAX_CUSTOM_PER_KIND;
        if (\count($raw) > $max) {
            throw new InvalidFuelPayloadException(sprintf('Too many products (max %d).', $max));
        }

        $products = [];
        $seen = [];
        $customCount = [FuelKind::Food->value => 0, FuelKind::Drink->value => 0];
        foreach ($raw as $i => $item) {
            $path = "products[$i]";
            if (!\is_array($item) || array_is_list($item)) {
                throw new InvalidFuelPayloadException("$path must be an object.");
            }
            $key = $item['key'] ?? null;
            if (!\is_string($key) || (!FuelCatalog::isBuiltIn($key) && !FuelCatalog::isCustomKey($key))) {
                throw new InvalidFuelPayloadException("$path.key is not a known or custom product.");
            }
            if (isset($seen[$key])) {
                throw new InvalidFuelPayloadException("$path.key is duplicated.");
            }
            $seen[$key] = true;

            $carbs = $item['carbs'] ?? null;
            if ((!\is_int($carbs) && !\is_float($carbs)) || !is_finite((float) $carbs) || $carbs < 0 || $carbs > FuelCatalog::MAX_CARBS) {
                throw new InvalidFuelPayloadException(sprintf('%s.carbs must be a number from 0 to %d.', $path, FuelCatalog::MAX_CARBS));
            }
            $carbs = round((float) $carbs, 1);

            if (FuelCatalog::isBuiltIn($key)) {
                $kind = (string) FuelCatalog::kindOf($key)?->value;
                $cat = (string) RaceNutritionCatalog::builtInCategory($key);
                if ((\array_key_exists('kind', $item) && $item['kind'] !== $kind) || (\array_key_exists('cat', $item) && $item['cat'] !== $cat)
                    || null !== ($item['label'] ?? null)) {
                    throw new InvalidFuelPayloadException("$path does not match the catalogue.");
                }
                $products[] = ['key' => $key, 'kind' => $kind, 'cat' => $cat, 'label' => null, 'carbs' => $carbs];
                continue;
            }

            $kind = \is_string($item['kind'] ?? null) ? FuelKind::tryFrom($item['kind']) : null;
            $cat = $item['cat'] ?? null;
            if (null === $kind || !\is_string($cat) || !RaceNutritionCatalog::categoryMatchesKind($cat, $kind)) {
                throw new InvalidFuelPayloadException("$path.kind / cat are invalid.");
            }
            $label = \is_string($item['label'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $item['label'])) : '';
            if ('' === $label || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > FuelCatalog::LABEL_MAX_LENGTH || 0 !== preg_match('/\p{Cc}/u', $label)) {
                throw new InvalidFuelPayloadException(sprintf('%s.label must be 1 to %d characters.', $path, FuelCatalog::LABEL_MAX_LENGTH));
            }
            if (++$customCount[$kind->value] > FuelCatalog::MAX_CUSTOM_PER_KIND) {
                throw new InvalidFuelPayloadException(sprintf('Too many custom %s products (max %d).', $kind->value, FuelCatalog::MAX_CUSTOM_PER_KIND));
            }
            $products[] = ['key' => $key, 'kind' => $kind->value, 'cat' => $cat, 'label' => $label, 'carbs' => $carbs];
        }

        return $products;
    }

    /**
     * @param array<string, array{kind: ?string, cat: ?string}> $known
     *
     * @return list<ManualRow>
     */
    private function manual(mixed $raw, array $known): array
    {
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new InvalidFuelPayloadException('manual must be a list.');
        }
        if (\count($raw) > self::MAX_MANUAL_ROWS) {
            throw new InvalidFuelPayloadException(sprintf('Too many manual rows (max %d).', self::MAX_MANUAL_ROWS));
        }

        $rows = [];
        $seen = [];
        foreach ($raw as $i => $row) {
            $path = "manual[$i]";
            if (!\is_array($row) || array_is_list($row)) {
                throw new InvalidFuelPayloadException("$path must be an object.");
            }
            $place = $row['place'] ?? null;
            if (RaceNutritionCalculator::PLACE_POINT !== $place && RaceNutritionCalculator::PLACE_SEGMENT !== $place) {
                throw new InvalidFuelPayloadException("$path.place must be \"point\" or \"segment\".");
            }
            $anchor = $row['anchor'] ?? null;
            if (\is_int($anchor) && $anchor > 0) {
                $anchor = (string) $anchor;
            }
            $edge = RaceNutritionCalculator::PLACE_POINT === $place ? 'start' : 'finish';
            if (!\is_string($anchor) || ($anchor !== $edge && 1 !== preg_match('/^[1-9]\d{0,9}$/', $anchor))) {
                throw new InvalidFuelPayloadException("$path.anchor is invalid.");
            }
            $rowKey = ('point' === $place ? 'p:' : 's:').$anchor;
            if (isset($seen[$rowKey])) {
                throw new InvalidFuelPayloadException("$path is duplicated.");
            }
            $seen[$rowKey] = true;

            $items = $row['items'] ?? null;
            if (!\is_array($items) || !array_is_list($items) || \count($items) > self::MAX_LINES_PER_ROW) {
                throw new InvalidFuelPayloadException(sprintf('%s.items must be a list of at most %d lines.', $path, self::MAX_LINES_PER_ROW));
            }
            $lines = [];
            $keys = [];
            foreach ($items as $j => $line) {
                $linePath = "$path.items[$j]";
                if (!\is_array($line) || array_is_list($line)) {
                    throw new InvalidFuelPayloadException("$linePath must be an object.");
                }
                $key = $line['key'] ?? null;
                if (!\is_string($key) || !isset($known[$key]) || isset($keys[$key])) {
                    throw new InvalidFuelPayloadException("$linePath.key is unknown or duplicated.");
                }
                $keys[$key] = true;
                $kind = FuelKind::from((string) $known[$key]['kind']);
                $qty = $line['qty'] ?? null;
                $max = FuelCatalog::maxQuantity($kind);
                if (!\is_int($qty) || $qty < 0 || $qty > $max || (FuelKind::Drink === $kind && 0 !== $qty % FuelCatalog::DRINK_ML_MULTIPLE)) {
                    throw new InvalidFuelPayloadException("$linePath.qty is out of range.");
                }
                if ($qty > 0) {
                    $lines[] = ['key' => $key, 'qty' => $qty];
                }
            }
            $rows[] = ['place' => $place, 'anchor' => $anchor, 'items' => $lines];
        }

        return $rows;
    }
}
