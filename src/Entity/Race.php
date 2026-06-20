<?php
namespace App\Entity;

use App\Repository\RaceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RaceRepository::class)]
class Race
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $distanceKm = null;

    #[ORM\Column(type: 'smallint')]
    private int $year;

    #[ORM\Column(type: 'string', length: 30, enumType: RaceIntent::class)]
    private RaceIntent $intent = RaceIntent::WantToDo;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isDone = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $l): static { $this->location = $l; return $this; }

    public function getDistanceKm(): ?float { return $this->distanceKm; }
    public function setDistanceKm(?float $d): static { $this->distanceKm = $d; return $this; }

    public function getYear(): int { return $this->year; }
    public function setYear(int $y): static { $this->year = $y; return $this; }

    public function getIntent(): RaceIntent { return $this->intent; }
    public function setIntent(RaceIntent $i): static { $this->intent = $i; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): static { $this->notes = $n; return $this; }

    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $w): static { $this->website = $w; return $this; }

    public function isDone(): bool { return $this->isDone; }
    public function setIsDone(bool $d): static { $this->isDone = $d; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
