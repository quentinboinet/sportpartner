<?php

namespace App\Entity;

use App\Repository\WeeklyGoalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Objectif hebdomadaire d'un utilisateur pour un sport : au plus un par sport (index unique
 * user_id + sport_id), au moins une cible renseignée. L'objectif global, tous sports confondus,
 * reste dans AthleteProfile (weeklyDistanceGoalKm, weeklySessionsGoal).
 *
 * Les messages de validation sont des clés de messages.*.yaml (domaine "messages").
 */
#[ORM\Entity(repositoryClass: WeeklyGoalRepository::class)]
#[ORM\Table(name: 'weeklyGoal')]
#[ORM\UniqueConstraint(name: 'uniq_weekly_goal_user_sport', columns: ['user_id', 'sport_id'])]
class WeeklyGoal
{
    public const DISTANCE_KM_MIN = 0.1;
    public const DISTANCE_KM_MAX = 2000;
    public const SESSIONS_MIN = 1;
    public const SESSIONS_MAX = 30;
    public const DURATION_MINUTES_MIN = 1;
    public const DURATION_MINUTES_MAX = 6000; // 100 h
    public const ELEVATION_M_MIN = 1;
    public const ELEVATION_M_MAX = 50000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'weeklyGoals')]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Sport::class)]
    #[ORM\JoinColumn(name: 'sport_id', nullable: false, onDelete: 'CASCADE')]
    private ?Sport $sport = null;

    /** Distance cible en km, arrondie au dixième. */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $distanceKm = null;

    #[ORM\Column(nullable: true)]
    private ?int $sessions = null;

    /** Durée cible en minutes (saisie en heures : "5", "5,5" ou "5:30"). */
    #[ORM\Column(nullable: true)]
    private ?int $durationMinutes = null;

    /** Dénivelé positif cible en mètres. */
    #[ORM\Column(nullable: true)]
    private ?int $elevationGainM = null;

    /** Ordre d'affichage (ordre des lignes dans les paramètres). */
    #[ORM\Column]
    private int $position = 0;

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getSport(): ?Sport { return $this->sport; }
    public function setSport(?Sport $sport): static { $this->sport = $sport; return $this; }

    public function getDistanceKm(): ?float { return $this->distanceKm; }
    public function setDistanceKm(?float $km): static
    {
        $this->distanceKm = $km === null ? null : round($km, 1);
        return $this;
    }

    public function getSessions(): ?int { return $this->sessions; }
    public function setSessions(?int $sessions): static { $this->sessions = $sessions; return $this; }

    public function getDurationMinutes(): ?int { return $this->durationMinutes; }
    public function setDurationMinutes(?int $minutes): static { $this->durationMinutes = $minutes; return $this; }

    public function getElevationGainM(): ?int { return $this->elevationGainM; }
    public function setElevationGainM(?int $meters): static { $this->elevationGainM = $meters; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }

    public function hasTarget(): bool
    {
        return $this->distanceKm !== null || $this->sessions !== null
            || $this->durationMinutes !== null || $this->elevationGainM !== null;
    }

    /** Recopie les cibles d'un autre objectif (même sport retiré puis rajouté dans une même saisie). */
    public function copyTargetsFrom(self $other): static
    {
        $this->distanceKm      = $other->distanceKm;
        $this->sessions        = $other->sessions;
        $this->durationMinutes = $other->durationMinutes;
        $this->elevationGainM  = $other->elevationGainM;

        return $this;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->sport === null) {
            $context->buildViolation('settings.goals.error.sport_required')
                ->setTranslationDomain('messages')
                ->addViolation();
        }

        $ranges = [
            'distanceKm'      => [$this->distanceKm, self::DISTANCE_KM_MIN, self::DISTANCE_KM_MAX, 'distance_range'],
            'sessions'        => [$this->sessions, self::SESSIONS_MIN, self::SESSIONS_MAX, 'sessions_range'],
            'durationMinutes' => [$this->durationMinutes, self::DURATION_MINUTES_MIN, self::DURATION_MINUTES_MAX, 'duration_range'],
            'elevationGainM'  => [$this->elevationGainM, self::ELEVATION_M_MIN, self::ELEVATION_M_MAX, 'elevation_range'],
        ];
        foreach ($ranges as $path => [$value, $min, $max, $key]) {
            if ($value !== null && ($value < $min || $value > $max)) {
                $context->buildViolation('settings.goals.error.'.$key)
                    ->setTranslationDomain('messages')
                    ->atPath($path)
                    ->addViolation();
            }
        }

        if (!$this->hasTarget()) {
            $context->buildViolation('settings.goals.error.no_target')
                ->setTranslationDomain('messages')
                ->addViolation();
        }
    }
}
