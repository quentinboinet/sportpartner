<?php

namespace App\Entity;

use App\Repository\HealthSessionExerciseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Exercice d'une séance enregistrée : dosage prévu (séries, répétitions ou secondes, repos) et ce qui a été fait.
 *
 * Nom, catégorie, mode et « chaque côté » sont recopiés à l'enregistrement : l'historique reste lisible si
 * l'exercice personnel est ensuite modifié ou supprimé (exercise repasse alors à null, ON DELETE SET NULL).
 *
 * performed : une valeur par série faite, dans l'ordre (répétitions, ou secondes tenues). Pour un exercice
 * « chaque côté », une série compte quand les deux côtés sont faits, avec la valeur du côté le moins long.
 */
#[ORM\Entity(repositoryClass: HealthSessionExerciseRepository::class)]
#[ORM\Table(name: 'healthSessionExercise')]
class HealthSessionExercise
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HealthSession::class, inversedBy: 'exercises')]
    #[ORM\JoinColumn(name: 'session_id', nullable: false, onDelete: 'CASCADE')]
    private HealthSession $session;

    #[ORM\ManyToOne(targetEntity: HealthExercise::class)]
    #[ORM\JoinColumn(name: 'exercise_id', nullable: true, onDelete: 'SET NULL')]
    private ?HealthExercise $exercise = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 0;

    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(type: 'string', length: 12, enumType: HealthCategory::class)]
    private HealthCategory $category = HealthCategory::Strength;

    #[ORM\Column]
    private bool $unilateral = false;

    #[ORM\Column(type: 'string', length: 5, enumType: HealthDosageMode::class)]
    private HealthDosageMode $mode = HealthDosageMode::Reps;

    /** Séries prévues. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $sets = 1;

    /** Répétitions ou secondes prévues par série. */
    #[ORM\Column]
    private int $value = 1;

    #[ORM\Column]
    private int $restSeconds = 0;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $performed = [];

    public function countSetsDone(): int
    {
        return count($this->performed);
    }

    /** Total fait : répétitions ou secondes (par côté pour un exercice « chaque côté »). */
    public function totalDone(): int
    {
        return array_sum($this->getPerformed());
    }

    public function getId(): ?int { return $this->id; }

    public function getSession(): HealthSession { return $this->session; }
    public function setSession(HealthSession $session): static { $this->session = $session; return $this; }

    public function getExercise(): ?HealthExercise { return $this->exercise; }
    public function setExercise(?HealthExercise $exercise): static { $this->exercise = $exercise; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getCategory(): HealthCategory { return $this->category; }
    public function setCategory(HealthCategory $category): static { $this->category = $category; return $this; }

    public function isUnilateral(): bool { return $this->unilateral; }
    public function setUnilateral(bool $unilateral): static { $this->unilateral = $unilateral; return $this; }

    public function getMode(): HealthDosageMode { return $this->mode; }
    public function setMode(HealthDosageMode $mode): static { $this->mode = $mode; return $this; }

    public function getSets(): int { return $this->sets; }
    public function setSets(int $sets): static { $this->sets = $sets; return $this; }

    public function getValue(): int { return $this->value; }
    public function setValue(int $value): static { $this->value = $value; return $this; }

    public function getRestSeconds(): int { return $this->restSeconds; }
    public function setRestSeconds(int $seconds): static { $this->restSeconds = $seconds; return $this; }

    /** @return list<int> */
    public function getPerformed(): array { return array_values(array_map('intval', $this->performed)); }
    /** @param list<int> $performed */
    public function setPerformed(array $performed): static { $this->performed = array_values($performed); return $this; }
}
