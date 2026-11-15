<?php
namespace App\Entity;

use App\Repository\RaceScenarioSplitRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Temps de passage visé (ARRIVÉE au repère) pour un scénario : secondes écoulées depuis le départ.
 * Le départ d'un ravitaillement vaut arrivée + RaceCheckpoint::stopSeconds (calculé, non stocké).
 */
#[ORM\Entity(repositoryClass: RaceScenarioSplitRepository::class)]
#[ORM\Table(name: 'raceScenarioSplit')]
#[ORM\UniqueConstraint(name: 'UNIQ_RACE_SCENARIO_SPLIT', columns: ['scenario_id', 'checkpoint_id'])]
class RaceScenarioSplit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RaceScenario $scenario;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RaceCheckpoint $checkpoint;

    /** Temps de passage visé à l'arrivée au repère, en secondes depuis le départ. */
    #[ORM\Column]
    private int $elapsedSeconds = 0;

    public function getId(): ?int { return $this->id; }

    public function getScenario(): RaceScenario { return $this->scenario; }
    public function setScenario(RaceScenario $s): static { $this->scenario = $s; return $this; }

    public function getCheckpoint(): RaceCheckpoint { return $this->checkpoint; }
    public function setCheckpoint(RaceCheckpoint $c): static { $this->checkpoint = $c; return $this; }

    public function getElapsedSeconds(): int { return $this->elapsedSeconds; }
    public function setElapsedSeconds(int $s): static { $this->elapsedSeconds = $s; return $this; }
}
