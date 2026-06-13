<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AthleteProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'profile')]
    #[ORM\JoinColumn(name: 'user', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $handle = null;

    #[ORM\Column(nullable: true)]
    private ?int $age = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $weight = null;

    #[ORM\Column(nullable: true)]
    private ?int $restingHr = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxHr = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $vma = null;

    #[ORM\Column(nullable: true)]
    private ?int $ftp = null;

    #[ORM\Column(nullable: true)]
    private ?int $weeklyDistanceGoalKm = null;

    #[ORM\Column(nullable: true)]
    private ?int $weeklySessionsGoal = null;

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getHandle(): ?string { return $this->handle; }
    public function setHandle(?string $handle): static { $this->handle = $handle; return $this; }

    public function getAge(): ?int { return $this->age; }
    public function setAge(?int $age): static { $this->age = $age; return $this; }

    public function getWeight(): ?float { return $this->weight; }
    public function setWeight(?float $weight): static { $this->weight = $weight; return $this; }

    public function getRestingHr(): ?int { return $this->restingHr; }
    public function setRestingHr(?int $hr): static { $this->restingHr = $hr; return $this; }

    public function getMaxHr(): ?int { return $this->maxHr; }
    public function setMaxHr(?int $hr): static { $this->maxHr = $hr; return $this; }

    public function getVma(): ?float { return $this->vma; }
    public function setVma(?float $vma): static { $this->vma = $vma; return $this; }

    public function getFtp(): ?int { return $this->ftp; }
    public function setFtp(?int $ftp): static { $this->ftp = $ftp; return $this; }

    public function getWeeklyDistanceGoalKm(): ?int { return $this->weeklyDistanceGoalKm; }
    public function setWeeklyDistanceGoalKm(?int $v): static { $this->weeklyDistanceGoalKm = $v; return $this; }

    public function getWeeklySessionsGoal(): ?int { return $this->weeklySessionsGoal; }
    public function setWeeklySessionsGoal(?int $v): static { $this->weeklySessionsGoal = $v; return $this; }
}
