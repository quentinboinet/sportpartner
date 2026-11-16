<?php
namespace App\Entity;

use App\Repository\RaceCheckpointRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Repère du plan de course (point de passage ou ravitaillement), commun à tous les scénarios.
 */
#[ORM\Entity(repositoryClass: RaceCheckpointRepository::class)]
#[ORM\Table(name: 'raceCheckpoint')]
class RaceCheckpoint
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Race $race;

    #[ORM\Column(length: 120)]
    private string $name;

    /** Position depuis le départ, en mètres. */
    #[ORM\Column]
    private int $distanceM = 0;

    #[ORM\Column(type: 'string', length: 30, enumType: CheckpointType::class)]
    private CheckpointType $type = CheckpointType::Point;

    /** Arrêt prévu au ravitaillement, en secondes. */
    #[ORM\Column(nullable: true)]
    private ?int $stopSeconds = null;

    /** Barrière horaire officielle : temps écoulé depuis le départ, en secondes (peut dépasser 24 h). */
    #[ORM\Column(nullable: true)]
    private ?int $cutoffSeconds = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * Message pour les proches, affiché sur la page publique du plan (RaceShare). Distinct de $notes (privées,
     * jamais partagées). 500 caractères au plus (RaceShare::MAX_POINT_MESSAGE_LENGTH).
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $publicMessage = null;

    /**
     * Assistance / sac d'allègement : on peut y récupérer SES produits (proches, sac déposé). Sert à la liste
     * « à emporter » du plan nutrition (App\Service\Nutrition\RaceNutritionCalculator::carryList). Défaut SQL 0
     * (migration Version20240031_CreateRaceNutrition).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $assistance = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRace(): Race { return $this->race; }
    public function setRace(Race $race): static { $this->race = $race; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }

    public function getDistanceM(): int { return $this->distanceM; }
    public function setDistanceM(int $d): static { $this->distanceM = $d; return $this; }

    public function getType(): CheckpointType { return $this->type; }
    public function setType(CheckpointType $t): static { $this->type = $t; return $this; }

    public function getStopSeconds(): ?int { return $this->stopSeconds; }
    public function setStopSeconds(?int $s): static { $this->stopSeconds = $s; return $this; }

    public function getCutoffSeconds(): ?int { return $this->cutoffSeconds; }
    public function setCutoffSeconds(?int $s): static { $this->cutoffSeconds = $s; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): static { $this->notes = $n; return $this; }

    public function getPublicMessage(): ?string { return $this->publicMessage; }
    public function setPublicMessage(?string $m): static { $this->publicMessage = $m; return $this; }

    public function hasAssistance(): bool { return $this->assistance; }
    public function setAssistance(bool $a): static { $this->assistance = $a; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
