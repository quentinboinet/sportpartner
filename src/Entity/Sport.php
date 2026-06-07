<?php

namespace App\Entity;

use App\Repository\SportRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SportRepository::class)]
#[ORM\Table(name: 'sports')]
class Sport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant URL-safe utilisé comme clé de traduction (ex: 'run', 'trail', 'weight_training') */
    #[ORM\Column(length: 50, unique: true)]
    private string $slug;

    /** Classe Bootstrap Icons (ex: 'bi-person-walking') */
    #[ORM\Column(length: 60)]
    private string $icon;

    /** Couleur hexadécimale (ex: '#E8400C') */
    #[ORM\Column(length: 7)]
    private string $color;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getIcon(): string { return $this->icon; }
    public function setIcon(string $icon): static { $this->icon = $icon; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): static { $this->color = $color; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $order): static { $this->sortOrder = $order; return $this; }
}
