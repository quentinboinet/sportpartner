<?php

namespace App\Tests\Unit\Service\Nutrition;

use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceNutritionItem;
use App\Entity\RaceNutritionPlan;
use App\Entity\RaceScenario;
use App\Repository\RaceNutritionItemRepository;
use App\Repository\RaceNutritionPlanRepository;
use App\Service\Nutrition\RaceNutritionStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Écritures du plan nutrition (RaceNutritionStore), base de données remplacée par des doublures : copie d'un scénario
 * vers un nouveau (duplication) et report des lignes manuelles d'un repère supprimé sur le tronçon suivant.
 */
final class RaceNutritionStoreTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /** @var list<object> */
    private array $removed = [];

    private static function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $func): mixed => $func($em));
        $em->method('persist')->willReturnCallback(function (object $entity): void { $this->persisted[] = $entity; });
        $em->method('remove')->willReturnCallback(function (object $entity): void { $this->removed[] = $entity; });

        return $em;
    }

    #[Test]
    public function duplicatingAScenarioCopiesSettingsProductsAndManualRows(): void
    {
        $race = (new Race())->setName('Course');
        $source = (new RaceScenario())->setRace($race)->setName('A');
        self::setId($source, 11);
        $copy = (new RaceScenario())->setRace($race)->setName('A (copie)'); // pas encore enregistré
        $checkpoint = (new RaceCheckpoint())->setRace($race)->setName('Ravito');
        self::setId($checkpoint, 7);

        $products = [['key' => 'custom_1', 'kind' => 'food', 'cat' => 'solid', 'label' => 'Banane', 'carbs' => 27.0]];
        $plan = (new RaceNutritionPlan())->setScenario($source)->setCarbsPerHour(85)->setFluidPerHour(650)->setMixDrink(20)
            ->setMixGel(50)->setMixSolid(30)->setSolidEarly(true)->setFlaskMl(600)->setGelKey('puree')->setSolidKey('custom_1')
            ->setDrinkKey(null)->setProducts($products);
        $rows = [
            (new RaceNutritionItem())->setPlan($plan)->setPlace('segment')->setCheckpoint($checkpoint)->setItems([['key' => 'custom_1', 'qty' => 2]]),
            (new RaceNutritionItem())->setPlan($plan)->setPlace('point')->setCheckpoint(null)->setItems([]),
        ];
        $plans = $this->createMock(RaceNutritionPlanRepository::class);
        $plans->expects(self::once())->method('findOneByScenario')->with($source)->willReturn($plan);
        $items = $this->createStub(RaceNutritionItemRepository::class);
        $items->method('findByPlan')->willReturn($rows);

        (new RaceNutritionStore($this->em(), $plans, $items))->copy($source, $copy);

        $newPlan = $this->persisted[0];
        self::assertInstanceOf(RaceNutritionPlan::class, $newPlan);
        self::assertSame($copy, $newPlan->getScenario());
        self::assertSame([85, 650, 20, 50, 30, true, 600, 'puree', 'custom_1', null, $products], [
            $newPlan->getCarbsPerHour(), $newPlan->getFluidPerHour(), $newPlan->getMixDrink(), $newPlan->getMixGel(), $newPlan->getMixSolid(),
            $newPlan->isSolidEarly(), $newPlan->getFlaskMl(), $newPlan->getGelKey(), $newPlan->getSolidKey(), $newPlan->getDrinkKey(), $newPlan->getProducts(),
        ]);
        $newRows = \array_slice($this->persisted, 1);
        self::assertCount(2, $newRows);
        foreach ($newRows as $i => $row) {
            self::assertInstanceOf(RaceNutritionItem::class, $row);
            self::assertSame($newPlan, $row->getPlan());
            self::assertSame($rows[$i]->rowKey(), $row->rowKey());
            self::assertSame($rows[$i]->getItems(), $row->getItems());
            self::assertNotSame($rows[$i], $row, 'new rows, the source keeps its own');
        }
        self::assertSame([], $this->removed);
    }

    #[Test]
    public function deletedCheckpointRowsAreMovedToTheNextLeg(): void
    {
        $race = (new Race())->setName('Course');
        $scenario = (new RaceScenario())->setRace($race)->setName('Objectif A');
        $plan = (new RaceNutritionPlan())->setScenario($scenario)->setProducts([['key' => 'custom_1', 'kind' => 'drink', 'cat' => 'drink', 'label' => 'Coca', 'carbs' => 53.0]]);
        self::setId($plan, 3);
        $cps = [];
        foreach ([5 => 1000, 6 => 2000, 7 => 3000] as $id => $distance) {
            $cps[$id] = (new RaceCheckpoint())->setRace($race)->setName('CP'.$id)->setDistanceM($distance);
            self::setId($cps[$id], $id);
        }
        $point = (new RaceNutritionItem())->setPlan($plan)->setPlace('point')->setCheckpoint($cps[6])->setItems([['key' => 'bar', 'qty' => 1]]);
        $leg = (new RaceNutritionItem())->setPlan($plan)->setPlace('segment')->setCheckpoint($cps[6])->setItems([['key' => 'custom_1', 'qty' => 250]]);

        $items = $this->createMock(RaceNutritionItemRepository::class);
        $items->method('findForCheckpointId')->with($race, 6)->willReturn([$point, $leg]);
        $items->expects(self::once())->method('findSegmentsEndingAt')->with($race, 7)->willReturn([]);
        $store = new RaceNutritionStore($this->em(), $this->createStub(RaceNutritionPlanRepository::class), $items);

        $names = $store->moveForDeletedCheckpoint($race, $cps[6], array_values($cps));

        self::assertSame(['Objectif A'], $names);
        self::assertCount(1, $this->persisted);
        $target = $this->persisted[0];
        self::assertInstanceOf(RaceNutritionItem::class, $target);
        self::assertSame('s:7', $target->rowKey(), 'leg arriving at the next checkpoint');
        self::assertSame([['key' => 'bar', 'qty' => 1], ['key' => 'custom_1', 'qty' => 250]], $target->getItems());
        self::assertSame([$point, $leg], $this->removed);
    }

    #[Test]
    public function nothingToMoveWhenTheCheckpointHasNoManualRow(): void
    {
        $race = new Race();
        $cp = (new RaceCheckpoint())->setRace($race)->setName('CP');
        self::setId($cp, 9);
        $items = $this->createStub(RaceNutritionItemRepository::class);
        $items->method('findForCheckpointId')->willReturn([]);
        $store = new RaceNutritionStore($this->em(), $this->createStub(RaceNutritionPlanRepository::class), $items);

        self::assertSame([], $store->moveForDeletedCheckpoint($race, $cp, [$cp]));
        self::assertSame([], $this->persisted);
    }
}
