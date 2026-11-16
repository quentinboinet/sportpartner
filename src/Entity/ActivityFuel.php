<?php

namespace App\Entity;

use App\Repository\ActivityFuelRepository;
use App\Service\Nutrition\FuelItem;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ravitaillement consommé pendant une activité : une ligne par aliment ou boisson.
 * Seules les lignes utiles sont stockées (quantité > 0, ou apport personnalisé) et elles sont
 * remplacées en bloc à chaque enregistrement (ActivityFuelRepository::replaceForActivity()).
 * Les calculs (totaux, g/h, cible) sont faits par App\Service\Nutrition\ActivityFuelCalculator.
 */
#[ORM\Entity(repositoryClass: ActivityFuelRepository::class)]
#[ORM\Table(name: 'activityFuel')]
#[ORM\UniqueConstraint(name: 'UNIQ_ACTIVITY_FUEL_ITEM', columns: ['activity_id', 'itemKey'])]
class ActivityFuel
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'activity_id', nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\Column(type: 'string', length: 10, enumType: FuelKind::class)]
    private FuelKind $kind;

    /** Clé du catalogue (gel, water...) ou "custom_<n>" pour un apport personnalisé (FuelCatalog). */
    #[ORM\Column(length: 40)]
    private string $itemKey;

    /** Nom saisi par l'utilisateur (apports personnalisés uniquement). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $label = null;

    /** Unités pour un aliment, millilitres pour une boisson. */
    #[ORM\Column]
    private int $quantity = 0;

    /** Glucides en grammes : par unité pour un aliment, pour 500 ml pour une boisson. */
    #[ORM\Column(type: 'float')]
    private float $carbsPerUnit = 0.0;

    public static function fromItem(Activity $activity, FuelItem $item): self
    {
        return (new self())
            ->setActivity($activity)
            ->setKind($item->kind)
            ->setItemKey($item->key)
            ->setLabel($item->label)
            ->setQuantity($item->quantity)
            ->setCarbsPerUnit($item->carbs);
    }

    public function toItem(): FuelItem
    {
        return new FuelItem($this->kind, $this->itemKey, $this->quantity, $this->carbsPerUnit, $this->label);
    }

    public function getId(): ?int { return $this->id; }

    public function getActivity(): Activity { return $this->activity; }
    public function setActivity(Activity $activity): static { $this->activity = $activity; return $this; }

    public function getKind(): FuelKind { return $this->kind; }
    public function setKind(FuelKind $kind): static { $this->kind = $kind; return $this; }

    public function getItemKey(): string { return $this->itemKey; }
    public function setItemKey(string $key): static { $this->itemKey = $key; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): static { $this->label = $label; return $this; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): static { $this->quantity = $quantity; return $this; }

    public function getCarbsPerUnit(): float { return $this->carbsPerUnit; }
    public function setCarbsPerUnit(float $carbs): static { $this->carbsPerUnit = $carbs; return $this; }
}
