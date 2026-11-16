<?php
namespace App\Entity;

use App\Repository\RaceShareRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Partage public du plan d'une course : lien /p/{token} envoyé aux proches (sans compte), qui affiche les horaires
 * prévus d'UN scénario, le parcours et des messages écrits pour eux. Une ligne par course au plus.
 *
 * - token : 32 caractères base64url (24 octets aléatoires, App\Service\Share\ShareToken), unique ; le régénérer rend
 *   l'ancien lien invalide (404) ;
 * - enabled : false = le lien répond 404 (le jeton est gardé : réactiver redonne le même lien) ;
 * - scenario : scénario partagé ; null (jamais choisi ou supprimé, ON DELETE SET NULL) = premier scénario par position ;
 * - messages du départ, de l'arrivée et message général ici ; message de chaque repère : RaceCheckpoint::publicMessage
 *   (distinct des notes privées du repère, jamais affichées sur la page publique).
 * Pas de relation inverse sur Race (même principe que RaceRoute) : la ligne disparaît avec la course (ON DELETE CASCADE).
 */
#[ORM\Entity(repositoryClass: RaceShareRepository::class)]
#[ORM\Table(name: 'raceShare')]
#[ORM\UniqueConstraint(name: 'UNIQ_RACE_SHARE_TOKEN', columns: ['token'])]
class RaceShare
{
    public const MAX_DISPLAY_NAME_LENGTH = 60;
    /** Message général (en tête de la page publique). */
    public const MAX_MESSAGE_LENGTH = 1000;
    /** Message du départ, de l'arrivée et de chaque repère. */
    public const MAX_POINT_MESSAGE_LENGTH = 500;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Race $race;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RaceScenario $scenario = null;

    #[ORM\Column(length: 32)]
    private string $token;

    #[ORM\Column]
    private bool $enabled = false;

    /** Nom affiché aux proches (texte libre), null = aucun nom. Jamais l'e-mail. */
    #[ORM\Column(length: self::MAX_DISPLAY_NAME_LENGTH, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column]
    private bool $showPaces = false;

    #[ORM\Column]
    private bool $showCutoffs = true;

    /**
     * Nutrition sur la page publique : ce que les proches doivent donner aux points d'assistance (scénario partagé
     * seulement). Désactivé par défaut ; défaut SQL 0 (migration Version20240031_CreateRaceNutrition).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $showNutrition = false;

    #[ORM\Column(length: self::MAX_MESSAGE_LENGTH, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(length: self::MAX_POINT_MESSAGE_LENGTH, nullable: true)]
    private ?string $startMessage = null;

    #[ORM\Column(length: self::MAX_POINT_MESSAGE_LENGTH, nullable: true)]
    private ?string $finishMessage = null;

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

    public function getScenario(): ?RaceScenario { return $this->scenario; }
    public function setScenario(?RaceScenario $scenario): static { $this->scenario = $scenario; return $this; }

    public function getToken(): string { return $this->token; }
    public function setToken(string $token): static { $this->token = $token; return $this; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): static { $this->enabled = $enabled; return $this; }

    public function getDisplayName(): ?string { return $this->displayName; }
    public function setDisplayName(?string $name): static { $this->displayName = $name; return $this; }

    public function isShowPaces(): bool { return $this->showPaces; }
    public function setShowPaces(bool $show): static { $this->showPaces = $show; return $this; }

    public function isShowCutoffs(): bool { return $this->showCutoffs; }
    public function setShowCutoffs(bool $show): static { $this->showCutoffs = $show; return $this; }

    public function isShowNutrition(): bool { return $this->showNutrition; }
    public function setShowNutrition(bool $show): static { $this->showNutrition = $show; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): static { $this->message = $message; return $this; }

    public function getStartMessage(): ?string { return $this->startMessage; }
    public function setStartMessage(?string $message): static { $this->startMessage = $message; return $this; }

    public function getFinishMessage(): ?string { return $this->finishMessage; }
    public function setFinishMessage(?string $message): static { $this->finishMessage = $message; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** Marque le partage comme modifié maintenant. */
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
