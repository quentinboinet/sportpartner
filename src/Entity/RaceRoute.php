<?php
namespace App\Entity;

use App\Repository\RaceRouteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Parcours GPX importé pour une course : seuls les points simplifiés sont stockés
 * (jamais le fichier brut). Pas de relation inverse sur Race, pour ne pas charger
 * les points à chaque liste de courses.
 */
#[ORM\Entity(repositoryClass: RaceRouteRepository::class)]
#[ORM\Table(name: 'raceRoute')]
class RaceRoute
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Race $race;

    #[ORM\Column(length: 255)]
    private string $fileName;

    /** Lien d'origine si le GPX a été importé par URL (permet de le réimporter), null si fichier. */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $sourceUrl = null;

    /** @var list<array{0: int, 1: float|null, 2: float, 3: float}> [distanceM, ele, lat, lon] */
    #[ORM\Column(type: Types::JSON)]
    private array $points = [];

    #[ORM\Column]
    private int $distanceM = 0;

    #[ORM\Column]
    private int $gainM = 0;

    #[ORM\Column]
    private int $lossM = 0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $minEle = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $maxEle = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRace(): Race { return $this->race; }
    public function setRace(Race $race): static { $this->race = $race; return $this; }

    public function getFileName(): string { return $this->fileName; }
    public function setFileName(string $f): static { $this->fileName = $f; return $this; }

    public function getSourceUrl(): ?string { return $this->sourceUrl; }
    public function setSourceUrl(?string $u): static { $this->sourceUrl = $u; return $this; }

    /** @return list<array{0: int, 1: float|null, 2: float, 3: float}> */
    public function getPoints(): array { return $this->points; }

    /** @param list<array{0: int, 1: float|null, 2: float, 3: float}> $points */
    public function setPoints(array $points): static { $this->points = $points; return $this; }

    public function getDistanceM(): int { return $this->distanceM; }
    public function setDistanceM(int $d): static { $this->distanceM = $d; return $this; }

    public function getGainM(): int { return $this->gainM; }
    public function setGainM(int $g): static { $this->gainM = $g; return $this; }

    public function getLossM(): int { return $this->lossM; }
    public function setLossM(int $l): static { $this->lossM = $l; return $this; }

    public function getMinEle(): ?float { return $this->minEle; }
    public function setMinEle(?float $e): static { $this->minEle = $e; return $this; }

    public function getMaxEle(): ?float { return $this->maxEle; }
    public function setMaxEle(?float $e): static { $this->maxEle = $e; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): static { $this->createdAt = $d; return $this; }
}
