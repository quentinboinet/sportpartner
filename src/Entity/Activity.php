<?php

namespace App\Entity;

use App\Repository\ActivityRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\Table(name: 'activities')]
#[ORM\HasLifecycleCallbacks]
class Activity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(nullable: true)]
    private ?int $stravaId = null;

    #[ORM\Column(length: 50)]
    private string $type = 'Run'; // Run, Trail, Ride, Walk, Swim

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $distanceMeters = null;

    #[ORM\Column(nullable: true)]
    private ?int $movingTimeSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $elapsedTimeSeconds = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $totalElevationGain = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $averageHeartrate = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $maxHeartrate = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $averageCadence = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $averageSpeed = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $timezone = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // Computed helpers
    public function getDistanceKm(): ?float
    {
        return $this->distanceMeters ? round($this->distanceMeters / 1000, 2) : null;
    }

    public function getDistanceMiles(): ?float
    {
        return $this->distanceMeters ? round($this->distanceMeters / 1609.344, 2) : null;
    }

    public function getPaceMinPerKm(): ?string
    {
        if (!$this->movingTimeSeconds || !$this->distanceMeters) return null;
        $paceSeconds = ($this->movingTimeSeconds / $this->distanceMeters) * 1000;
        return sprintf('%d:%02d', floor($paceSeconds / 60), $paceSeconds % 60);
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getStravaId(): ?int { return $this->stravaId; }
    public function setStravaId(?int $id): static { $this->stravaId = $id; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): static { $this->name = $name; return $this; }
    public function getDistanceMeters(): ?float { return $this->distanceMeters; }
    public function setDistanceMeters(?float $d): static { $this->distanceMeters = $d; return $this; }
    public function getMovingTimeSeconds(): ?int { return $this->movingTimeSeconds; }
    public function setMovingTimeSeconds(?int $t): static { $this->movingTimeSeconds = $t; return $this; }
    public function getTotalElevationGain(): ?float { return $this->totalElevationGain; }
    public function setTotalElevationGain(?float $d): static { $this->totalElevationGain = $d; return $this; }
    public function getAverageHeartrate(): ?float { return $this->averageHeartrate; }
    public function setAverageHeartrate(?float $hr): static { $this->averageHeartrate = $hr; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(?\DateTimeImmutable $date): static { $this->startDate = $date; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
