<?php

namespace App\Entity;

use App\Repository\HealthExerciseUserPrefRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Préférences d'un utilisateur pour un exercice FOURNI : son propre lien vidéo et le masquage dans la
 * bibliothèque. Une ligne au plus par utilisateur et par exercice, supprimée quand elle ne porte plus rien.
 */
#[ORM\Entity(repositoryClass: HealthExerciseUserPrefRepository::class)]
#[ORM\Table(name: 'healthExerciseUserPref')]
#[ORM\UniqueConstraint(name: 'UNIQ_HEALTH_PREF_USER_EXERCISE', columns: ['user_id', 'exercise_id'])]
class HealthExerciseUserPref
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: HealthExercise::class)]
    #[ORM\JoinColumn(name: 'exercise_id', nullable: false, onDelete: 'CASCADE')]
    private HealthExercise $exercise;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $videoUrl = null;

    #[ORM\Column]
    private bool $hidden = false;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, HealthExercise $exercise)
    {
        $this->user = $user;
        $this->exercise = $exercise;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Plus rien à retenir : la ligne peut être supprimée. */
    public function isEmpty(): bool
    {
        return $this->videoUrl === null && !$this->hidden;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getExercise(): HealthExercise { return $this->exercise; }

    public function getVideoUrl(): ?string { return $this->videoUrl; }
    public function setVideoUrl(?string $url): static { $this->videoUrl = $url; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function isHidden(): bool { return $this->hidden; }
    public function setHidden(bool $hidden): static { $this->hidden = $hidden; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
