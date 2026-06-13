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

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $stravaId = null;

    #[ORM\ManyToOne(targetEntity: Sport::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Sport $sport = null;

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

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summaryPolyline = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $elevationProfile = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $splitsMetric = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $heartrateStream = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $weatherData = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Computed helpers ───────────────────────────────────────

    public function getDistanceKm(): ?float
    {
        return $this->distanceMeters ? round($this->distanceMeters / 1000, 2) : null;
    }

    public function getDistanceMiles(): ?float
    {
        return $this->distanceMeters ? round($this->distanceMeters / 1609.344, 2) : null;
    }

    /** Pace as formatted string 'M:SS', null if not applicable */
    public function getPaceMinPerKm(): ?string
    {
        if (!$this->movingTimeSeconds || !$this->distanceMeters) {
            return null;
        }
        $paceSeconds = ($this->movingTimeSeconds / $this->distanceMeters) * 1000;

        return sprintf('%d:%02d', (int) floor($paceSeconds / 60), (int) ($paceSeconds % 60));
    }

    /** Speed in km/h (useful for cycling) */
    public function getSpeedKmh(): ?float
    {
        return $this->averageSpeed ? round($this->averageSpeed * 3.6, 1) : null;
    }

    /** Slug du sport — alias de getSport()->getSlug() pour la compatibilité ascendante */
    public function getType(): ?string
    {
        return $this->sport?->getSlug();
    }

    // ── Getters / Setters ──────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getStravaId(): ?int { return $this->stravaId; }
    public function setStravaId(?int $id): static { $this->stravaId = $id; return $this; }

    public function getSport(): ?Sport { return $this->sport; }
    public function setSport(?Sport $sport): static { $this->sport = $sport; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): static { $this->name = $name; return $this; }

    public function getDistanceMeters(): ?float { return $this->distanceMeters; }
    public function setDistanceMeters(?float $d): static { $this->distanceMeters = $d; return $this; }

    public function getMovingTimeSeconds(): ?int { return $this->movingTimeSeconds; }
    public function setMovingTimeSeconds(?int $t): static { $this->movingTimeSeconds = $t; return $this; }

    public function getElapsedTimeSeconds(): ?int { return $this->elapsedTimeSeconds; }
    public function setElapsedTimeSeconds(?int $t): static { $this->elapsedTimeSeconds = $t; return $this; }

    public function getTotalElevationGain(): ?float { return $this->totalElevationGain; }
    public function setTotalElevationGain(?float $d): static { $this->totalElevationGain = $d; return $this; }

    public function getAverageHeartrate(): ?float { return $this->averageHeartrate; }
    public function setAverageHeartrate(?float $hr): static { $this->averageHeartrate = $hr; return $this; }

    public function getMaxHeartrate(): ?float { return $this->maxHeartrate; }
    public function setMaxHeartrate(?float $hr): static { $this->maxHeartrate = $hr; return $this; }

    public function getAverageCadence(): ?float { return $this->averageCadence; }
    public function setAverageCadence(?float $c): static { $this->averageCadence = $c; return $this; }

    public function getAverageSpeed(): ?float { return $this->averageSpeed; }
    public function setAverageSpeed(?float $s): static { $this->averageSpeed = $s; return $this; }

    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(?\DateTimeImmutable $date): static { $this->startDate = $date; return $this; }

    public function getTimezone(): ?string { return $this->timezone; }
    public function setTimezone(?string $tz): static { $this->timezone = $tz; return $this; }

    public function getSummaryPolyline(): ?string { return $this->summaryPolyline; }
    public function setSummaryPolyline(?string $p): static { $this->summaryPolyline = $p; return $this; }

    public function getElevationProfile(): ?string { return $this->elevationProfile; }
    public function setElevationProfile(?string $json): static { $this->elevationProfile = $json; return $this; }

    public function getSplitsMetric(): ?string { return $this->splitsMetric; }
    public function setSplitsMetric(?string $json): static { $this->splitsMetric = $json; return $this; }

    public function getHeartrateStream(): ?string { return $this->heartrateStream; }
    public function setHeartrateStream(?string $json): static { $this->heartrateStream = $json; return $this; }

    public function getWeatherData(): ?string { return $this->weatherData; }
    public function setWeatherData(?string $json): static { $this->weatherData = $json; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
