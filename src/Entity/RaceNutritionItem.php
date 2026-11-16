<?php
namespace App\Entity;

use App\Repository\RaceNutritionItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne MANUELLE du plan nutrition d'un scénario : ce que l'utilisateur a fixé pour un point ou un tronçon (les
 * autres lignes sont "auto", calculées à chaque affichage et jamais stockées, comme les temps de passage auto).
 *
 * Emplacement (place + checkpoint) :
 *  - place "point"   : au repère checkpoint ; checkpoint null = au départ ;
 *  - place "segment" : sur le tronçon qui ARRIVE au repère checkpoint ; checkpoint null = tronçon qui arrive à
 *    l'arrivée. Un tronçon est identifié par son point d'arrivée : l'identifiant reste stable quand un repère est
 *    ajouté avant lui (règles des repères : App\Service\Nutrition\RaceNutritionRows).
 * items : list<array{key: string, qty: int}>, quantités en unités (aliment) ou en ml (boisson) ; une liste vide =
 * "rien ici", fixé par l'utilisateur. Unicité (plan, place, checkpoint) garantie par le code (remplacement en bloc).
 * Repère supprimé : ON DELETE CASCADE, après report des apports sur le tronçon suivant (RacePlanController).
 */
#[ORM\Entity(repositoryClass: RaceNutritionItemRepository::class)]
#[ORM\Table(name: 'raceNutritionItem')]
class RaceNutritionItem
{
    public const PLACE_POINT = 'point';
    public const PLACE_SEGMENT = 'segment';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RaceNutritionPlan $plan;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?RaceCheckpoint $checkpoint = null;

    #[ORM\Column(length: 10)]
    private string $place = self::PLACE_SEGMENT;

    /** @var list<array{key: string, qty: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $items = [];

    public function getId(): ?int { return $this->id; }

    public function getPlan(): RaceNutritionPlan { return $this->plan; }
    public function setPlan(RaceNutritionPlan $plan): static { $this->plan = $plan; return $this; }

    public function getCheckpoint(): ?RaceCheckpoint { return $this->checkpoint; }
    public function setCheckpoint(?RaceCheckpoint $checkpoint): static { $this->checkpoint = $checkpoint; return $this; }

    public function getPlace(): string { return $this->place; }
    public function setPlace(string $place): static { $this->place = $place; return $this; }

    /** @return list<array{key: string, qty: int}> */
    public function getItems(): array { return $this->items; }

    /** @param list<array{key: string, qty: int}> $items */
    public function setItems(array $items): static { $this->items = $items; return $this; }

    /**
     * Clé de la ligne dans le plan calculé : "p:start", "p:<id>", "s:<id>" ou "s:finish"
     * (voir RaceNutritionCalculator).
     */
    public function rowKey(): string
    {
        $id = $this->checkpoint?->getId();
        if (self::PLACE_POINT === $this->place) {
            return 'p:'.(null === $id ? 'start' : (string) $id);
        }

        return 's:'.(null === $id ? 'finish' : (string) $id);
    }
}
