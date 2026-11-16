<?php

namespace App\Entity;

use App\Repository\HealthExerciseTextRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Textes d'un exercice de l'espace Santé dans une langue (une ligne par exercice et par langue). Les longueurs
 * maximales ci-dessous valent pour les exercices fournis (vérifiées par les tests de la migration) comme pour les
 * exercices personnels (HealthExerciseValidator).
 */
#[ORM\Entity(repositoryClass: HealthExerciseTextRepository::class)]
#[ORM\Table(name: 'healthExerciseText')]
#[ORM\UniqueConstraint(name: 'UNIQ_HEALTH_EXERCISE_TEXT_LOCALE', columns: ['exercise_id', 'locale'])]
class HealthExerciseText
{
    public const NAME_MAX = 80;
    public const MUSCLES_MAX = 160;
    public const START_MAX = 500;
    public const STEPS_MAX = 8;
    public const STEP_MAX = 300;
    public const BREATHING_MAX = 255;
    public const MISTAKES_MAX = 6;
    public const MISTAKE_MAX = 200;
    public const VARIATION_MAX = 300;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HealthExercise::class, inversedBy: 'texts')]
    #[ORM\JoinColumn(name: 'exercise_id', nullable: false, onDelete: 'CASCADE')]
    private HealthExercise $exercise;

    /** « fr », « en » (langue de l'interface pour un exercice personnel). */
    #[ORM\Column(length: 5)]
    private string $locale = 'fr';

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name = '';

    #[ORM\Column(length: self::MUSCLES_MAX, nullable: true)]
    private ?string $targetMuscles = null;

    #[ORM\Column(length: self::START_MAX, nullable: true)]
    private ?string $startPosition = null;

    /** @var list<string> étapes du mouvement, dans l'ordre */
    #[ORM\Column(type: Types::JSON)]
    private array $steps = [];

    #[ORM\Column(length: self::BREATHING_MAX, nullable: true)]
    private ?string $breathing = null;

    /** @var list<string> erreurs fréquentes */
    #[ORM\Column(type: Types::JSON)]
    private array $mistakes = [];

    /** Variante plus facile. */
    #[ORM\Column(length: self::VARIATION_MAX, nullable: true)]
    private ?string $easier = null;

    /** Variante plus difficile. */
    #[ORM\Column(length: self::VARIATION_MAX, nullable: true)]
    private ?string $harder = null;

    public function getId(): ?int { return $this->id; }

    public function getExercise(): HealthExercise { return $this->exercise; }
    public function setExercise(HealthExercise $exercise): static { $this->exercise = $exercise; return $this; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getTargetMuscles(): ?string { return $this->targetMuscles; }
    public function setTargetMuscles(?string $muscles): static { $this->targetMuscles = $muscles; return $this; }

    public function getStartPosition(): ?string { return $this->startPosition; }
    public function setStartPosition(?string $start): static { $this->startPosition = $start; return $this; }

    /** @return list<string> */
    public function getSteps(): array { return array_values(array_map('strval', $this->steps)); }
    /** @param list<string> $steps */
    public function setSteps(array $steps): static { $this->steps = array_values($steps); return $this; }

    public function getBreathing(): ?string { return $this->breathing; }
    public function setBreathing(?string $breathing): static { $this->breathing = $breathing; return $this; }

    /** @return list<string> */
    public function getMistakes(): array { return array_values(array_map('strval', $this->mistakes)); }
    /** @param list<string> $mistakes */
    public function setMistakes(array $mistakes): static { $this->mistakes = array_values($mistakes); return $this; }

    public function getEasier(): ?string { return $this->easier; }
    public function setEasier(?string $easier): static { $this->easier = $easier; return $this; }

    public function getHarder(): ?string { return $this->harder; }
    public function setHarder(?string $harder): static { $this->harder = $harder; return $this; }
}
