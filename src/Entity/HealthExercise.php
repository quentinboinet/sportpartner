<?php

namespace App\Entity;

use App\Repository\HealthExerciseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Exercice de l'espace Santé : fourni (user NULL, slug stable, inséré par migration comme les marques de matériel)
 * ou personnel (créé par un utilisateur, slug NULL, visible de lui seul).
 *
 * Textes : une ligne HealthExerciseText par langue. Un exercice fourni a une ligne « fr » et une ligne « en » ; un
 * exercice personnel en a une seule, dans la langue de l'interface au moment de sa création, affichée telle quelle
 * dans toutes les langues (textFor()).
 *
 * Dosage par défaut : séries x (répétitions ou secondes), repos en secondes entre les séries. La vidéo (videoUrl)
 * n'est utilisée que pour un exercice personnel ; pour un exercice fourni, chaque utilisateur peut noter son propre
 * lien (HealthExerciseUserPref).
 */
#[ORM\Entity(repositoryClass: HealthExerciseRepository::class)]
#[ORM\Table(name: 'healthExercise')]
class HealthExercise
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** Propriétaire d'un exercice personnel ; null pour un exercice fourni. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** Clé stable d'un exercice fourni (« glute-bridge ») : sert aux migrations de mise à jour du contenu. */
    #[ORM\Column(length: 60, unique: true, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(type: 'string', length: 12, enumType: HealthCategory::class)]
    private HealthCategory $category = HealthCategory::Strength;

    #[ORM\Column(type: 'string', length: 12, enumType: HealthLevel::class)]
    private HealthLevel $level = HealthLevel::Beginner;

    /** @var list<string> valeurs de HealthBodyArea */
    #[ORM\Column(type: Types::JSON)]
    private array $bodyAreas = [];

    /** @var list<string> valeurs de HealthEquipment ; vide = sans matériel */
    #[ORM\Column(type: Types::JSON)]
    private array $equipment = [];

    /** « Chaque côté » : chaque série est faite à gauche puis à droite. */
    #[ORM\Column]
    private bool $unilateral = false;

    #[ORM\Column(type: 'string', length: 5, enumType: HealthDosageMode::class)]
    private HealthDosageMode $dosageMode = HealthDosageMode::Reps;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $defaultSets = 3;

    /** Répétitions ou secondes par série (selon dosageMode). */
    #[ORM\Column]
    private int $defaultValue = 10;

    #[ORM\Column]
    private int $defaultRestSeconds = 45;

    /** Ordre d'un exercice fourni dans sa catégorie (les exercices personnels sont rangés par nom). */
    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $videoUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<string, HealthExerciseText> indexée par langue */
    #[ORM\OneToMany(targetEntity: HealthExerciseText::class, mappedBy: 'exercise', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $texts;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->texts = new ArrayCollection();
    }

    public function isCustom(): bool
    {
        return $this->user !== null;
    }

    /** Fourni, ou personnel de cet utilisateur. */
    public function isVisibleTo(User $user): bool
    {
        return $this->user === null || $this->user->getId() === $user->getId();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user !== null && $this->user->getId() === $user->getId();
    }

    /**
     * Textes à afficher : la langue demandée, sinon le français, sinon la seule langue disponible (exercice
     * personnel, affiché tel qu'il a été écrit).
     */
    public function textFor(string $locale): ?HealthExerciseText
    {
        $lang = substr($locale, 0, 2);
        foreach ([$lang, 'fr'] as $candidate) {
            if (isset($this->texts[$candidate])) {
                return $this->texts[$candidate];
            }
        }
        $first = $this->texts->first();

        return $first instanceof HealthExerciseText ? $first : null;
    }

    public function addText(HealthExerciseText $text): static
    {
        $text->setExercise($this);
        $this->texts[$text->getLocale()] = $text;

        return $this;
    }

    // ── Getters / Setters ──────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $slug): static { $this->slug = $slug; return $this; }

    public function getCategory(): HealthCategory { return $this->category; }
    public function setCategory(HealthCategory $category): static { $this->category = $category; return $this; }

    public function getLevel(): HealthLevel { return $this->level; }
    public function setLevel(HealthLevel $level): static { $this->level = $level; return $this; }

    /** @return list<HealthBodyArea> valeurs inconnues ignorées */
    public function getBodyAreas(): array
    {
        return array_values(array_filter(array_map(static fn (string $v): ?HealthBodyArea => HealthBodyArea::tryFrom($v), $this->bodyAreas)));
    }

    /** @param list<HealthBodyArea> $areas */
    public function setBodyAreas(array $areas): static
    {
        $this->bodyAreas = array_values(array_unique(array_map(static fn (HealthBodyArea $a): string => $a->value, $areas)));

        return $this;
    }

    /** @return list<HealthEquipment> valeurs inconnues ignorées */
    public function getEquipment(): array
    {
        return array_values(array_filter(array_map(static fn (string $v): ?HealthEquipment => HealthEquipment::tryFrom($v), $this->equipment)));
    }

    /** @param list<HealthEquipment> $equipment */
    public function setEquipment(array $equipment): static
    {
        $this->equipment = array_values(array_unique(array_map(static fn (HealthEquipment $e): string => $e->value, $equipment)));

        return $this;
    }

    public function isUnilateral(): bool { return $this->unilateral; }
    public function setUnilateral(bool $unilateral): static { $this->unilateral = $unilateral; return $this; }

    public function getDosageMode(): HealthDosageMode { return $this->dosageMode; }
    public function setDosageMode(HealthDosageMode $mode): static { $this->dosageMode = $mode; return $this; }

    public function getDefaultSets(): int { return $this->defaultSets; }
    public function setDefaultSets(int $sets): static { $this->defaultSets = $sets; return $this; }

    public function getDefaultValue(): int { return $this->defaultValue; }
    public function setDefaultValue(int $value): static { $this->defaultValue = $value; return $this; }

    public function getDefaultRestSeconds(): int { return $this->defaultRestSeconds; }
    public function setDefaultRestSeconds(int $seconds): static { $this->defaultRestSeconds = $seconds; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }

    public function getVideoUrl(): ?string { return $this->videoUrl; }
    public function setVideoUrl(?string $url): static { $this->videoUrl = $url; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $at): static { $this->updatedAt = $at; return $this; }

    /** @return Collection<string, HealthExerciseText> */
    public function getTexts(): Collection { return $this->texts; }
}
