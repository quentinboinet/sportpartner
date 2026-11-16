<?php

namespace App\Service\Nutrition;

use App\Entity\FuelKind;
use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceNutritionItem;
use App\Entity\RaceNutritionPlan;
use App\Entity\RaceScenario;
use App\Repository\RaceNutritionItemRepository;
use App\Repository\RaceNutritionPlanRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écritures du plan nutrition des scénarios : enregistrement de l'état saisi (section Nutrition, JSON), copie d'un
 * scénario à l'autre (et duplication d'un scénario), report des lignes manuelles d'un repère supprimé
 * (règles : RaceNutritionRows). Les lignes manuelles sont remplacées en bloc : DELETE exécuté tout de suite, puis
 * INSERT (Doctrine exécuterait les INSERT avant les DELETE d'un flush).
 *
 * @phpstan-import-type State from RaceNutritionPayloadValidator
 * @phpstan-import-type Line from RaceNutritionRows
 */
final class RaceNutritionStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RaceNutritionPlanRepository $plans,
        private readonly RaceNutritionItemRepository $items,
    ) {
    }

    /**
     * Enregistre l'état validé du plan d'un scénario (le plan est créé au premier enregistrement). Les lignes
     * manuelles du départ, de l'arrivée et des repères du tableau ($tableCheckpointIds) sont remplacées par celles de
     * l'état ; celles d'autres repères (au-delà du tracé) sont gardées, et celles de l'état qui visent un repère hors
     * du tableau sont ignorées.
     *
     * @param State     $state
     * @param list<int> $tableCheckpointIds
     */
    public function save(RaceScenario $scenario, array $state, array $tableCheckpointIds): void
    {
        $this->em->wrapInTransaction(function () use ($scenario, $state, $tableCheckpointIds): void {
            $plan = $this->plans->findOneByScenario($scenario);
            if (null === $plan) {
                $plan = (new RaceNutritionPlan())->setScenario($scenario);
                $this->em->persist($plan);
            }
            $s = $state['settings'];
            $plan->setCarbsPerHour($s['carbsPerHour'])->setFluidPerHour($s['fluidPerHour'])
                ->setMixDrink($s['mixDrink'])->setMixGel($s['mixGel'])->setMixSolid($s['mixSolid'])
                ->setSolidEarly($s['solidEarly'])->setFlaskMl($s['flaskMl'])
                ->setGelKey($s['gel'])->setSolidKey($s['solid'])->setDrinkKey($s['drink'])
                ->setProducts($state['products'])
                ->touch();
            $this->em->flush();

            $qb = $this->em->createQueryBuilder()->delete(RaceNutritionItem::class, 'i')
                ->where('i.plan = :plan')
                ->setParameter('plan', $plan);
            if ([] === $tableCheckpointIds) {
                $qb->andWhere('i.checkpoint IS NULL');
            } else {
                $qb->andWhere('i.checkpoint IS NULL OR IDENTITY(i.checkpoint) IN (:ids)')->setParameter('ids', $tableCheckpointIds);
            }
            $qb->getQuery()->execute();

            $allowed = array_fill_keys($tableCheckpointIds, true);
            foreach ($state['manual'] as $row) {
                $edge = \in_array($row['anchor'], ['start', 'finish'], true);
                if (!$edge && !isset($allowed[(int) $row['anchor']])) {
                    continue;
                }
                $this->em->persist((new RaceNutritionItem())
                    ->setPlan($plan)
                    ->setPlace($row['place'])
                    ->setCheckpoint($edge ? null : $this->em->getReference(RaceCheckpoint::class, (int) $row['anchor']))
                    ->setItems($row['items']));
            }
        });
    }

    /**
     * Copie le plan nutrition de $source sur $target (même course) : réglages, produits et lignes manuelles ; celles de
     * $target sont remplacées. Les apports auto seront recalculés avec les temps de $target. Sans plan enregistré pour
     * $source, $target revient aux réglages par défaut. Utilisé aussi par la duplication d'un scénario ($target pas
     * encore enregistré).
     */
    public function copy(RaceScenario $source, RaceScenario $target): void
    {
        $this->em->wrapInTransaction(function () use ($source, $target): void {
            $sourcePlan = $this->plans->findOneByScenario($source);
            $targetPlan = null !== $target->getId() ? $this->plans->findOneByScenario($target) : null;
            if (null !== $targetPlan) {
                $this->em->createQuery('DELETE FROM '.RaceNutritionItem::class.' i WHERE i.plan = :plan')
                    ->setParameter('plan', $targetPlan)
                    ->execute();
            }
            if (null === $sourcePlan) {
                if (null !== $targetPlan) {
                    $this->em->remove($targetPlan);
                }

                return;
            }

            $targetPlan ??= (new RaceNutritionPlan())->setScenario($target);
            $targetPlan->setCarbsPerHour($sourcePlan->getCarbsPerHour())->setFluidPerHour($sourcePlan->getFluidPerHour())
                ->setMixDrink($sourcePlan->getMixDrink())->setMixGel($sourcePlan->getMixGel())->setMixSolid($sourcePlan->getMixSolid())
                ->setSolidEarly($sourcePlan->isSolidEarly())->setFlaskMl($sourcePlan->getFlaskMl())
                ->setGelKey($sourcePlan->getGelKey())->setSolidKey($sourcePlan->getSolidKey())->setDrinkKey($sourcePlan->getDrinkKey())
                ->setProducts($sourcePlan->getProducts())
                ->touch();
            $this->em->persist($targetPlan);
            foreach ($this->items->findByPlan($sourcePlan) as $row) {
                $this->em->persist((new RaceNutritionItem())
                    ->setPlan($targetPlan)
                    ->setPlace($row->getPlace())
                    ->setCheckpoint($row->getCheckpoint())
                    ->setItems($row->getItems()));
            }
        });
    }

    /** Nombre de lignes manuelles enregistrées du plan d'un scénario (0 sans plan). */
    public function manualCount(RaceScenario $scenario): int
    {
        $plan = $this->plans->findOneByScenario($scenario);

        return null !== $plan ? \count($this->items->findByPlan($plan)) : 0;
    }

    /**
     * Avant la suppression d'un repère : reporte, dans chaque scénario de la course, les lignes manuelles du point et
     * du tronçon qui y arrive sur le tronçon suivant (RaceNutritionRows::afterCheckpointDeleted). À appeler dans la
     * même transaction que la suppression ; les lignes du repère disparaissent ensuite par ON DELETE CASCADE.
     *
     * @param list<RaceCheckpoint> $ordered tous les repères de la course, triés par distance puis id
     *
     * @return list<string> noms des scénarios dont des apports ont été reportés
     */
    public function moveForDeletedCheckpoint(Race $race, RaceCheckpoint $deleted, array $ordered): array
    {
        $deletedId = (int) $deleted->getId();
        $rows = $this->items->findForCheckpointId($race, $deletedId);
        if ([] === $rows) {
            return [];
        }

        $next = null;
        foreach ($ordered as $i => $cp) {
            if ($cp->getId() === $deletedId) {
                $next = $ordered[$i + 1] ?? null;
            }
        }
        $nextId = $next?->getId();

        $byPlan = [];
        foreach ($rows as $row) {
            $byPlan[(int) $row->getPlan()->getId()][] = $row;
        }
        $targetRows = $this->items->findSegmentsEndingAt($race, $nextId);
        $names = [];
        foreach ($byPlan as $planRows) {
            $plan = $planRows[0]->getPlan();
            $manual = [];
            foreach ($planRows as $row) {
                $manual[$row->rowKey()] = $row->getItems();
            }
            $targetRow = null;
            foreach ($targetRows as $candidate) {
                if ($candidate->getPlan() === $plan) {
                    $targetRow = $candidate;
                }
            }
            $targetKey = 's:'.(null === $nextId ? 'finish' : (string) $nextId);
            if (null !== $targetRow) {
                $manual[$targetKey] = $targetRow->getItems();
            }

            $result = RaceNutritionRows::afterCheckpointDeleted($manual, $deletedId, $nextId, self::kinds($plan));
            if (!$result['moved']) {
                continue;
            }
            if (null === $targetRow) {
                $targetRow = (new RaceNutritionItem())->setPlan($plan)->setPlace(RaceNutritionItem::PLACE_SEGMENT)->setCheckpoint($next);
                $this->em->persist($targetRow);
            }
            $targetRow->setItems($result['manual'][$result['target']]);
            foreach ($planRows as $row) {
                $this->em->remove($row);
            }
            $plan->touch();
            $names[] = $plan->getScenario()->getName();
        }

        return array_values(array_unique($names));
    }

    /**
     * Scénarios de la course qui ont des apports manuels sur le tronçon qui arrive à ce repère (null = à l'arrivée) :
     * message après l'ajout d'un repère qui coupe ce tronçon, ou le déplacement du repère.
     *
     * @return list<string> noms des scénarios
     */
    public function scenariosWithManualSegment(Race $race, ?int $checkpointId): array
    {
        $names = array_map(static fn (RaceNutritionItem $row): string => $row->getPlan()->getScenario()->getName(), $this->items->findSegmentsEndingAt($race, $checkpointId));

        return array_values(array_unique($names));
    }

    /**
     * Type de chaque produit d'un plan (catalogue + personnalisés).
     *
     * @return array<string, string>
     */
    private static function kinds(RaceNutritionPlan $plan): array
    {
        $kinds = [];
        foreach (FuelCatalog::builtInKeys() as $key) {
            $kinds[$key] = FuelCatalog::kindOf($key)?->value ?? FuelKind::Food->value;
        }
        foreach ($plan->getProducts() as $product) {
            $kinds[$product['key']] = $product['kind'];
        }

        return $kinds;
    }
}
