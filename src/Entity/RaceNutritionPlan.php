<?php
namespace App\Entity;

use App\Repository\RaceNutritionPlanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Plan nutrition d'un scénario de course (un au plus par scénario) : réglages du calcul auto et produits utilisés.
 *
 *  - cibles : glucides (g/h) et hydratation (ml/h), sur le temps EN MOUVEMENT du scénario ;
 *  - répartition des glucides (%) : boisson / gels / solide, somme = 100 ; solidEarly décale le solide vers le début ;
 *  - produits du calcul auto : gelKey, solidKey, drinkKey (null = catégorie non utilisée ; boisson null = eau seule) ;
 *  - products : liste JSON de TOUS les produits du plan, catalogue de App\Service\Nutrition\FuelCatalog (glucides
 *    enregistrés pour ce plan) et produits personnalisés (custom_<n>, nom, catégorie) :
 *    list<array{key: string, kind: 'food'|'drink', cat: 'gel'|'solid'|'drink', label: ?string, carbs: float}> ;
 *  - flaskMl : contenance d'une flasque, pour la liste « à emporter ».
 * Sans ligne enregistrée, le plan d'un scénario utilise les valeurs par défaut (RaceNutritionCatalog::defaultSettings).
 * Les apports auto ne sont jamais stockés ; les lignes MANUELLES sont dans RaceNutritionItem.
 * Pas de relation inverse sur RaceScenario (comme RaceRoute) : la ligne disparaît avec le scénario (ON DELETE CASCADE).
 */
#[ORM\Entity(repositoryClass: RaceNutritionPlanRepository::class)]
#[ORM\Table(name: 'raceNutritionPlan')]
class RaceNutritionPlan
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private RaceScenario $scenario;

    /** Glucides visés, g par heure en mouvement. */
    #[ORM\Column]
    private int $carbsPerHour = 70;

    /** Hydratation visée, ml par heure en mouvement. */
    #[ORM\Column]
    private int $fluidPerHour = 500;

    /** Part des glucides apportée par la boisson (%). */
    #[ORM\Column]
    private int $mixDrink = 30;

    /** Part des glucides apportée par les gels (%). */
    #[ORM\Column]
    private int $mixGel = 40;

    /** Part des glucides apportée par le solide (%). */
    #[ORM\Column]
    private int $mixSolid = 30;

    /** Solide plutôt en début de course, gels plutôt ensuite. */
    #[ORM\Column]
    private bool $solidEarly = false;

    /** Contenance d'une flasque (ml). */
    #[ORM\Column]
    private int $flaskMl = 500;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $gelKey = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $solidKey = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $drinkKey = null;

    /** @var list<array{key: string, kind: string, cat: string, label: ?string, carbs: float}> */
    #[ORM\Column(type: Types::JSON)]
    private array $products = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getScenario(): RaceScenario { return $this->scenario; }
    public function setScenario(RaceScenario $scenario): static { $this->scenario = $scenario; return $this; }

    public function getCarbsPerHour(): int { return $this->carbsPerHour; }
    public function setCarbsPerHour(int $v): static { $this->carbsPerHour = $v; return $this; }

    public function getFluidPerHour(): int { return $this->fluidPerHour; }
    public function setFluidPerHour(int $v): static { $this->fluidPerHour = $v; return $this; }

    public function getMixDrink(): int { return $this->mixDrink; }
    public function setMixDrink(int $v): static { $this->mixDrink = $v; return $this; }

    public function getMixGel(): int { return $this->mixGel; }
    public function setMixGel(int $v): static { $this->mixGel = $v; return $this; }

    public function getMixSolid(): int { return $this->mixSolid; }
    public function setMixSolid(int $v): static { $this->mixSolid = $v; return $this; }

    public function isSolidEarly(): bool { return $this->solidEarly; }
    public function setSolidEarly(bool $v): static { $this->solidEarly = $v; return $this; }

    public function getFlaskMl(): int { return $this->flaskMl; }
    public function setFlaskMl(int $v): static { $this->flaskMl = $v; return $this; }

    public function getGelKey(): ?string { return $this->gelKey; }
    public function setGelKey(?string $k): static { $this->gelKey = $k; return $this; }

    public function getSolidKey(): ?string { return $this->solidKey; }
    public function setSolidKey(?string $k): static { $this->solidKey = $k; return $this; }

    public function getDrinkKey(): ?string { return $this->drinkKey; }
    public function setDrinkKey(?string $k): static { $this->drinkKey = $k; return $this; }

    /** @return list<array{key: string, kind: string, cat: string, label: ?string, carbs: float}> */
    public function getProducts(): array { return $this->products; }

    /** @param list<array{key: string, kind: string, cat: string, label: ?string, carbs: float}> $products */
    public function setProducts(array $products): static { $this->products = $products; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** Marque le plan comme modifié maintenant. */
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
