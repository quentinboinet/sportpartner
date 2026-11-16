<?php

namespace App\Entity;

use App\Repository\HealthSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Séance guidée faite dans l'espace Santé (historique) : exercices avec ce qui a été réellement fait, durée,
 * ressenti (RPE 1-10) et notes.
 *
 * startedAt est en UTC, comme Activity::startDate des activités Strava ; timezone est le fuseau du navigateur
 * (« Europe/Paris ») qui sert à l'affichage (getLocalStart()).
 *
 * activity : l'activité créée à l'enregistrement (case « Ajouter à mes activités »), null sinon ou si elle a été
 * supprimée (ON DELETE SET NULL). Pas de relation inverse sur Activity.
 */
#[ORM\Entity(repositoryClass: HealthSessionRepository::class)]
#[ORM\Table(name: 'healthSession')]
#[ORM\Index(name: 'IDX_HEALTH_SESSION_USER_STARTED', columns: ['user_id', 'startedAt'])]
class HealthSession
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\OneToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?Activity $activity = null;

    #[ORM\Column(length: 80)]
    private string $name = '';

    /** Début de la séance, heure UTC. */
    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $timezone = null;

    /** Durée active (pauses exclues), en secondes. */
    #[ORM\Column]
    private int $durationSeconds = 0;

    /** Durée du début à la fin, pauses comprises, en secondes. */
    #[ORM\Column]
    private int $elapsedSeconds = 0;

    /** Ressenti de l'effort, de 1 (très facile) à 10 (maximal). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $rpe = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, HealthSessionExercise> */
    #[ORM\OneToMany(targetEntity: HealthSessionExercise::class, mappedBy: 'session', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $exercises;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = new \DateTimeImmutable();
        $this->exercises = new ArrayCollection();
    }

    /** Début de la séance (UTC), quel que soit le fuseau par défaut de PHP. */
    public function getStartedAtUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->startedAt->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    /** Début de la séance dans le fuseau du navigateur qui l'a enregistrée (fuseau de PHP à défaut). */
    public function getLocalStart(): \DateTimeImmutable
    {
        try {
            $tz = new \DateTimeZone($this->timezone ?? date_default_timezone_get());
        } catch (\Exception) {
            $tz = new \DateTimeZone(date_default_timezone_get());
        }

        return $this->getStartedAtUtc()->setTimezone($tz);
    }

    public function addExercise(HealthSessionExercise $exercise): static
    {
        $exercise->setSession($this);
        $this->exercises->add($exercise);

        return $this;
    }

    /** Nombre total de séries faites (pour un exercice « chaque côté », une série compte quand les deux côtés sont faits). */
    public function countSetsDone(): int
    {
        $sets = 0;
        foreach ($this->exercises as $exercise) {
            $sets += $exercise->countSetsDone();
        }

        return $sets;
    }

    // ── Getters / Setters ──────────────────────────────────────

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }

    public function getActivity(): ?Activity { return $this->activity; }
    public function setActivity(?Activity $activity): static { $this->activity = $activity; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    /** $at est converti en UTC avant d'être stocké. */
    public function setStartedAt(\DateTimeImmutable $at): static { $this->startedAt = $at->setTimezone(new \DateTimeZone('UTC')); return $this; }

    public function getTimezone(): ?string { return $this->timezone; }
    public function setTimezone(?string $tz): static { $this->timezone = $tz; return $this; }

    public function getDurationSeconds(): int { return $this->durationSeconds; }
    public function setDurationSeconds(int $s): static { $this->durationSeconds = $s; return $this; }

    public function getElapsedSeconds(): int { return $this->elapsedSeconds; }
    public function setElapsedSeconds(int $s): static { $this->elapsedSeconds = $s; return $this; }

    public function getRpe(): ?int { return $this->rpe; }
    public function setRpe(?int $rpe): static { $this->rpe = $rpe; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, HealthSessionExercise> */
    public function getExercises(): Collection { return $this->exercises; }
}
