<?php
namespace App\Entity;

use App\Repository\RaceScenarioRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Scénario de course (ex. "Objectif A 3h15", "Finir") : heure de départ, temps cible à l'arrivée,
 * stratégie d'allure (PacingStrategy + PacingIntensity, qui façonnent les temps auto) et temps de passage
 * visés aux repères (RaceScenarioSplit). Plusieurs scénarios par course.
 * Les temps de passage ne sont pas mappés en collection : ils sont chargés en une requête
 * pour tous les scénarios d'une course (RaceScenarioSplitRepository::findForScenarios).
 */
#[ORM\Entity(repositoryClass: RaceScenarioRepository::class)]
#[ORM\Table(name: 'raceScenario')]
class RaceScenario
{
    public const MAX_NAME_LENGTH = 60;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Race $race;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    /** Heure de départ (heure du jour), optionnelle. */
    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startTime = null;

    /** Temps cible à l'arrivée : secondes écoulées depuis le départ (peut dépasser 24 h). */
    #[ORM\Column(nullable: true)]
    private ?int $targetSeconds = null;

    /**
     * Stratégie d'allure : module la répartition des temps auto (SplitCalculator::fillAutoFromEfforts).
     * Défaut SQL 'even' (migration Version20240029_AddScenarioStrategy) : les scénarios existants restent réguliers.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: PacingStrategy::class, options: ['default' => 'even'])]
    private PacingStrategy $strategy = PacingStrategy::Even;

    /** Intensité de la stratégie (sans effet pour "Régulier"). Défaut SQL 'medium'. */
    #[ORM\Column(type: Types::STRING, length: 10, enumType: PacingIntensity::class, options: ['default' => 'medium'])]
    private PacingIntensity $strategyIntensity = PacingIntensity::Medium;

    /** Ordre d'affichage (0 = premier). */
    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRace(): Race { return $this->race; }
    public function setRace(Race $race): static { $this->race = $race; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }

    public function getStartTime(): ?\DateTimeImmutable { return $this->startTime; }
    public function setStartTime(?\DateTimeImmutable $t): static { $this->startTime = $t; return $this; }

    public function getTargetSeconds(): ?int { return $this->targetSeconds; }
    public function setTargetSeconds(?int $s): static { $this->targetSeconds = $s; return $this; }

    public function getStrategy(): PacingStrategy { return $this->strategy; }
    public function setStrategy(PacingStrategy $s): static { $this->strategy = $s; return $this; }

    public function getStrategyIntensity(): PacingIntensity { return $this->strategyIntensity; }
    public function setStrategyIntensity(PacingIntensity $i): static { $this->strategyIntensity = $i; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): static { $this->position = $p; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $d): static { $this->updatedAt = $d; return $this; }

    /** Marque le scénario comme modifié maintenant. */
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
