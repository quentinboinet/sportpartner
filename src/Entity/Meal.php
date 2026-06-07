<?php
namespace App\Entity;

use App\Repository\MealRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MealRepository::class)]
class Meal
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $mealDate;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $mealTime = null;

    #[ORM\Column(type: 'string', length: 30, enumType: MealType::class)]
    private MealType $mealType = MealType::Lunch;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $carbsG = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $proteinsG = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $fatsG = null;

    #[ORM\Column(nullable: true)]
    private ?int $kcal = null;

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getMealDate(): \DateTimeImmutable { return $this->mealDate; }
    public function setMealDate(\DateTimeImmutable $d): static { $this->mealDate = $d; return $this; }

    public function getMealTime(): ?string { return $this->mealTime; }
    public function setMealTime(?string $t): static { $this->mealTime = $t; return $this; }

    public function getMealType(): MealType { return $this->mealType; }
    public function setMealType(MealType $t): static { $this->mealType = $t; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }

    public function getCarbsG(): ?float { return $this->carbsG; }
    public function setCarbsG(?float $v): static { $this->carbsG = $v; return $this; }

    public function getProteinsG(): ?float { return $this->proteinsG; }
    public function setProteinsG(?float $v): static { $this->proteinsG = $v; return $this; }

    public function getFatsG(): ?float { return $this->fatsG; }
    public function setFatsG(?float $v): static { $this->fatsG = $v; return $this; }

    public function getKcal(): ?int { return $this->kcal; }
    public function setKcal(?int $v): static { $this->kcal = $v; return $this; }

    public function computeKcal(): int
    {
        return (int)(($this->carbsG ?? 0) * 4 + ($this->proteinsG ?? 0) * 4 + ($this->fatsG ?? 0) * 9);
    }

    public function getEffectiveKcal(): int
    {
        return $this->kcal ?? $this->computeKcal();
    }
}
