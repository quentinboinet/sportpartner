<?php
namespace App\Entity;

use App\Repository\GearBrandRepository;
use Doctrine\ORM\Mapping as ORM;

/** Marque de matériel (table de référence, remplie par la migration Version20240024_CreateGear). */
#[ORM\Entity(repositoryClass: GearBrandRepository::class)]
#[ORM\Table(name: 'gearBrand')]
class GearBrand
{
    /** Nom de la marque générique, toujours proposée en dernier. */
    public const OTHER = 'Autre';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $name;

    #[ORM\Column]
    private bool $forShoes = false;

    #[ORM\Column]
    private bool $forBike = false;

    /** Ordre d'affichage (alphabétique, "Autre" à la fin). */
    #[ORM\Column]
    private int $position = 0;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function isForShoes(): bool { return $this->forShoes; }
    public function setForShoes(bool $v): static { $this->forShoes = $v; return $this; }

    public function isForBike(): bool { return $this->forBike; }
    public function setForBike(bool $v): static { $this->forBike = $v; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): static { $this->position = $p; return $this; }

    public function supports(GearType $type): bool
    {
        return match ($type) {
            GearType::Shoes => $this->forShoes,
            GearType::Bike => $this->forBike,
        };
    }

    public function isOther(): bool
    {
        return $this->name === self::OTHER;
    }
}
