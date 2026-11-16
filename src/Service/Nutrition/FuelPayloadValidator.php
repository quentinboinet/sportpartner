<?php

namespace App\Service\Nutrition;

use App\Entity\FuelKind;

/**
 * Validation du JSON envoyé par la carte Ravitaillement (POST /activities/{id}/fuel) :
 *
 *   {"items": [{"kind": "food", "key": "gel", "quantity": 2, "carbs": 25},
 *              {"kind": "food", "key": "custom_1", "label": "Banane", "quantity": 1, "carbs": 27},
 *              {"kind": "drink", "key": "water", "quantity": 500, "carbs": 0}]}
 *
 *  - kind : "food" | "drink" ; key : clé du catalogue de ce type, ou custom_<n> (1 à 999) avec un label ;
 *  - label : nom d'un apport personnalisé (1 à 60 caractères, espaces normalisés), absent ou null sinon ;
 *  - quantity : entier, 0 à 100 unités (aliment) ou 0 à 20 000 ml par multiples de 50 (boisson) ;
 *  - carbs : nombre de 0 à 200 g (par unité, ou pour 500 ml), arrondi au dixième ;
 *  - clés uniques, au plus 10 apports personnalisés par type ; un apport du catalogue absent vaut 0.
 * La moindre erreur rejette toute la saisie (InvalidFuelPayloadException).
 */
final class FuelPayloadValidator
{
    /**
     * @return list<FuelItem> toutes les lignes valides, dans l'ordre reçu (y compris celles à 0)
     *
     * @throws InvalidFuelPayloadException
     */
    public function validate(mixed $payload): array
    {
        if (!is_array($payload) || !array_key_exists('items', $payload) || !is_array($payload['items']) || !array_is_list($payload['items'])) {
            throw new InvalidFuelPayloadException('Expected an object with an "items" list.');
        }
        // Tous les apports du catalogue + les apports personnalisés autorisés
        $maxItems = count(FuelCatalog::builtInKeys()) + 2 * FuelCatalog::MAX_CUSTOM_PER_KIND;
        if (count($payload['items']) > $maxItems) {
            throw new InvalidFuelPayloadException(sprintf('Too many items (max %d).', $maxItems));
        }

        $items = [];
        $seen = [];
        $customCount = [FuelKind::Food->value => 0, FuelKind::Drink->value => 0];
        foreach ($payload['items'] as $i => $raw) {
            $item = $this->validateItem($raw, "items[$i]");
            if (isset($seen[$item->key])) {
                throw new InvalidFuelPayloadException(sprintf('items[%d].key "%s" is duplicated.', $i, $item->key));
            }
            $seen[$item->key] = true;
            if ($item->isCustom() && ++$customCount[$item->kind->value] > FuelCatalog::MAX_CUSTOM_PER_KIND) {
                throw new InvalidFuelPayloadException(sprintf('Too many custom %s items (max %d).', $item->kind->value, FuelCatalog::MAX_CUSTOM_PER_KIND));
            }
            $items[] = $item;
        }

        return $items;
    }

    private function validateItem(mixed $raw, string $path): FuelItem
    {
        if (!is_array($raw) || array_is_list($raw)) {
            throw new InvalidFuelPayloadException("$path must be an object.");
        }

        $kind = is_string($raw['kind'] ?? null) ? FuelKind::tryFrom($raw['kind']) : null;
        if ($kind === null) {
            throw new InvalidFuelPayloadException("$path.kind must be \"food\" or \"drink\".");
        }

        $key = $raw['key'] ?? null;
        if (!is_string($key)) {
            throw new InvalidFuelPayloadException("$path.key must be a string.");
        }
        $custom = FuelCatalog::isCustomKey($key);
        if (!$custom && FuelCatalog::kindOf($key) !== $kind) {
            throw new InvalidFuelPayloadException(sprintf('%s.key "%s" is not a known %s item.', $path, mb_substr($key, 0, 40), $kind->value));
        }

        $label = $raw['label'] ?? null;
        if ($custom) {
            $label = is_string($label) ? self::normalizeLabel($label) : '';
            if ($label === '' || mb_strlen($label) > FuelCatalog::LABEL_MAX_LENGTH || preg_match('/\p{Cc}/u', $label) !== 0) {
                throw new InvalidFuelPayloadException(sprintf('%s.label must be 1 to %d characters for a custom item.', $path, FuelCatalog::LABEL_MAX_LENGTH));
            }
        } elseif ($label !== null) {
            throw new InvalidFuelPayloadException("$path.label is only allowed for custom items.");
        }

        $quantity = $raw['quantity'] ?? null;
        $max = FuelCatalog::maxQuantity($kind);
        if (!is_int($quantity) || $quantity < 0 || $quantity > $max
            || ($kind === FuelKind::Drink && $quantity % FuelCatalog::DRINK_ML_MULTIPLE !== 0)) {
            throw new InvalidFuelPayloadException($kind === FuelKind::Food
                ? sprintf('%s.quantity must be an integer from 0 to %d.', $path, $max)
                : sprintf('%s.quantity must be a multiple of %d from 0 to %d (ml).', $path, FuelCatalog::DRINK_ML_MULTIPLE, $max));
        }

        $carbs = $raw['carbs'] ?? null;
        if ((!is_int($carbs) && !is_float($carbs)) || !is_finite((float) $carbs) || $carbs < 0 || $carbs > FuelCatalog::MAX_CARBS) {
            throw new InvalidFuelPayloadException(sprintf('%s.carbs must be a number from 0 to %d.', $path, FuelCatalog::MAX_CARBS));
        }

        return new FuelItem($kind, $key, $quantity, round((float) $carbs, 1), $custom ? $label : null);
    }

    /** Espaces de début / fin retirés, suites d'espaces réduites à un seul. */
    private static function normalizeLabel(string $label): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $label));
    }
}
