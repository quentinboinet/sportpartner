<?php

namespace App\Repository;

use App\Entity\RaceNutritionPlan;
use App\Entity\RaceScenario;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RaceNutritionPlan>
 */
class RaceNutritionPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RaceNutritionPlan::class);
    }

    public function findOneByScenario(RaceScenario $scenario): ?RaceNutritionPlan
    {
        return $this->findOneBy(['scenario' => $scenario]);
    }

    /**
     * Plans de plusieurs scénarios en une requête.
     *
     * @param list<RaceScenario> $scenarios
     *
     * @return array<int, RaceNutritionPlan> scenarioId => plan (absent : pas de plan enregistré)
     */
    public function findForScenarios(array $scenarios): array
    {
        $ids = array_values(array_filter(array_map(static fn (RaceScenario $s): ?int => $s->getId(), $scenarios)));
        if ([] === $ids) {
            return [];
        }

        /** @var list<RaceNutritionPlan> $plans */
        $plans = $this->createQueryBuilder('p')
            ->where('p.scenario IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($plans as $plan) {
            $indexed[(int) $plan->getScenario()->getId()] = $plan;
        }

        return $indexed;
    }
}
