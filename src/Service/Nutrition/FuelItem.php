<?php

namespace App\Service\Nutrition;

use App\Entity\FuelKind;

/**
 * Une ligne du ravitaillement (saisie validée, ligne stockée ou ligne affichée).
 * quantity : unités (aliment) ou ml (boisson) ; carbs : grammes par unité (aliment) ou pour 500 ml (boisson).
 */
final class FuelItem
{
    public function __construct(
        public readonly FuelKind $kind,
        public readonly string $key,
        public readonly int $quantity,
        public readonly float $carbs,
        public readonly ?string $label = null,
    ) {
    }

    public function isCustom(): bool
    {
        return FuelCatalog::isCustomKey($this->key);
    }

    /** Ligne à stocker : quantité > 0, ou apport personnalisé (gardé même à 0 tant qu'il n'est pas supprimé). */
    public function isStorable(): bool
    {
        return $this->quantity > 0 || $this->isCustom();
    }

    public function icon(): string
    {
        return FuelCatalog::icon($this->key, $this->kind);
    }
}
