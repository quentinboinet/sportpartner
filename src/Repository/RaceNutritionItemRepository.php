<?php

namespace App\Repository;

use App\Entity\Race;
use App\Entity\RaceNutritionItem;
use App\Entity\RaceNutritionPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RaceNutritionItem>
 */
class RaceNutritionItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RaceNutritionItem::class);
    }

    /**
     * Lignes manuelles de plusieurs plans en une requête.
     *
     * @param list<RaceNutritionPlan> $plans
     *
     * @return array<int, list<RaceNutritionItem>> planId => lignes (chaque plan demandé a une entrée)
     */
    public function findForPlans(array $plans): array
    {
        $result = [];
        foreach ($plans as $plan) {
            if (null !== $plan->getId()) {
                $result[$plan->getId()] = [];
            }
        }
        if ([] === $result) {
            return [];
        }

        /** @var list<RaceNutritionItem> $rows */
        $rows = $this->createQueryBuilder('i')
            ->where('i.plan IN (:ids)')
            ->setParameter('ids', array_keys($result))
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
        foreach ($rows as $row) {
            $result[(int) $row->getPlan()->getId()][] = $row;
        }

        return $result;
    }

    /** @return list<RaceNutritionItem> */
    public function findByPlan(RaceNutritionPlan $plan): array
    {
        return $this->findBy(['plan' => $plan], ['id' => 'ASC']);
    }

    /**
     * Lignes manuelles de tous les scénarios d'une course qui concernent ce repère (point ou tronçon qui y arrive).
     *
     * @return list<RaceNutritionItem>
     */
    public function findForCheckpointId(Race $race, int $checkpointId): array
    {
        /** @var list<RaceNutritionItem> $rows */
        $rows = $this->createQueryBuilder('i')
            ->join('i.plan', 'p')
            ->join('p.scenario', 's')
            ->where('s.race = :race')
            ->andWhere('IDENTITY(i.checkpoint) = :cp')
            ->setParameter('race', $race)
            ->setParameter('cp', $checkpointId)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Lignes manuelles de tronçon qui arrivent à ce repère (checkpointId null = à l'arrivée), tous scénarios de la
     * course confondus.
     *
     * @return list<RaceNutritionItem>
     */
    public function findSegmentsEndingAt(Race $race, ?int $checkpointId): array
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.plan', 'p')
            ->join('p.scenario', 's')
            ->where('s.race = :race')
            ->andWhere('i.place = :place')
            ->setParameter('race', $race)
            ->setParameter('place', RaceNutritionItem::PLACE_SEGMENT);
        if (null === $checkpointId) {
            $qb->andWhere('i.checkpoint IS NULL');
        } else {
            $qb->andWhere('IDENTITY(i.checkpoint) = :cp')->setParameter('cp', $checkpointId);
        }

        /** @var list<RaceNutritionItem> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
