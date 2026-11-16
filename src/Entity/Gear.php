<?php
namespace App\Entity;

use App\Repository\GearRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Matériel d'un utilisateur (chaussures, vélo), rattaché à ses activités pour suivre l'usure. */
#[ORM\Entity(repositoryClass: GearRepository::class)]
#[ORM\Table(name: 'gear')]
#[ORM\UniqueConstraint(name: 'UNIQ_GEAR_USER_STRAVA', columns: ['user_id', 'stravaGearId'])]
class Gear
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 10, enumType: GearType::class)]
    private GearType $type;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private GearBrand $brand;

    #[ORM\Column(length: 120)]
    private string $model;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nickname = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $purchasedAt = null;

    /** Distance parcourue avant le suivi dans l'application, en mètres. */
    #[ORM\Column(options: ['default' => 0])]
    private int $initialDistanceM = 0;

    /** Durée de vie visée, en mètres (null = usure non suivie). */
    #[ORM\Column(nullable: true)]
    private ?int $maxDistanceM = null;

    /** Matériel par défaut de son type (au plus un par utilisateur et par type, garanti par le code). */
    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** Identifiant Strava ("b123" vélo, "g456" chaussures) du matériel importé ; null s'il a été saisi à la main. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $stravaGearId = null;

    /**
     * Id de l'activité Strava la plus récente lors du dernier import. Le kilométrage initial (total
     * Strava - activités rattachées) compte déjà les activités Strava d'id inférieur ou égal qui
     * n'étaient pas encore importées : voir deductImportedStravaActivity().
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $stravaLastActivityId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Libellé court : surnom, sinon marque + modèle. */
    public function getDisplayName(): string
    {
        return $this->nickname ?? $this->getFullName();
    }

    /** Marque + modèle (la marque générique "Autre" n'est pas affichée). */
    public function getFullName(): string
    {
        return $this->brand->isOther() ? $this->model : $this->brand->getName().' '.$this->model;
    }

    public function isRetired(): bool
    {
        return $this->retiredAt !== null;
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getType(): GearType { return $this->type; }
    public function setType(GearType $type): static { $this->type = $type; return $this; }

    public function getBrand(): GearBrand { return $this->brand; }
    public function setBrand(GearBrand $brand): static { $this->brand = $brand; return $this; }

    public function getModel(): string { return $this->model; }
    public function setModel(string $model): static { $this->model = $model; return $this; }

    public function getNickname(): ?string { return $this->nickname; }
    public function setNickname(?string $nickname): static { $this->nickname = $nickname; return $this; }

    public function getPurchasedAt(): ?\DateTimeImmutable { return $this->purchasedAt; }
    public function setPurchasedAt(?\DateTimeImmutable $d): static { $this->purchasedAt = $d; return $this; }

    public function getInitialDistanceM(): int { return $this->initialDistanceM; }
    public function setInitialDistanceM(int $m): static { $this->initialDistanceM = $m; return $this; }

    public function getMaxDistanceM(): ?int { return $this->maxDistanceM; }
    public function setMaxDistanceM(?int $m): static { $this->maxDistanceM = $m; return $this; }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $v): static { $this->isDefault = $v; return $this; }

    public function getRetiredAt(): ?\DateTimeImmutable { return $this->retiredAt; }
    public function setRetiredAt(?\DateTimeImmutable $d): static { $this->retiredAt = $d; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getStravaGearId(): ?string { return $this->stravaGearId; }
    public function setStravaGearId(?string $id): static { $this->stravaGearId = $id; return $this; }

    public function isFromStrava(): bool { return $this->stravaGearId !== null; }

    public function getStravaLastActivityId(): ?int { return $this->stravaLastActivityId; }
    public function setStravaLastActivityId(?int $id): static { $this->stravaLastActivityId = $id; return $this; }

    /**
     * La synchro rattache à ce matériel une nouvelle activité Strava. Si elle existait déjà sur
     * Strava lors du dernier import (id <= stravaLastActivityId), sa distance est déjà comptée dans
     * le kilométrage initial : on l'en retire pour ne pas la compter deux fois.
     */
    public function deductImportedStravaActivity(int $stravaActivityId, ?float $distanceM): void
    {
        if ($this->stravaLastActivityId === null || $stravaActivityId > $this->stravaLastActivityId) {
            return;
        }
        $this->initialDistanceM = max(0, $this->initialDistanceM - (int) round(max(0.0, $distanceM ?? 0.0)));
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
