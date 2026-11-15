<?php
namespace App\Repository;

use App\Entity\RaceScenario;
use App\Entity\RaceScenarioSplit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RaceScenarioSplit>
 */
class RaceScenarioSplitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RaceScenarioSplit::class);
    }

    /**
     * Temps de passage de plusieurs scénarios, en une seule requête (sans hydrater d'entités).
     * Chaque scénario demandé a une entrée (tableau vide s'il n'a aucun temps).
     *
     * @param list<RaceScenario> $scenarios
     *
     * @return array<int, array<int, int>> scenarioId => [checkpointId => elapsedSeconds]
     */
    public function findForScenarios(array $scenarios): array
    {
        $result = [];
        foreach ($scenarios as $scenario) {
            $result[(int) $scenario->getId()] = [];
        }
        if ($result === []) {
            return [];
        }

        /** @var list<array{scenarioId: int|string, checkpointId: int|string, elapsedSeconds: int|string}> $rows */
        $rows = $this->createQueryBuilder('sp')
            ->select('IDENTITY(sp.scenario) AS scenarioId', 'IDENTITY(sp.checkpoint) AS checkpointId', 'sp.elapsedSeconds AS elapsedSeconds')
            ->where('sp.scenario IN (:ids)')
            ->setParameter('ids', array_keys($result))
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $result[(int) $row['scenarioId']][(int) $row['checkpointId']] = (int) $row['elapsedSeconds'];
        }

        return $result;
    }

    /**
     * Entités des temps de passage d'un scénario, indexées par id de repère (pour un upsert).
     *
     * @return array<int, RaceScenarioSplit>
     */
    public function findByScenarioIndexed(RaceScenario $scenario): array
    {
        $indexed = [];
        foreach ($this->findBy(['scenario' => $scenario]) as $split) {
            $indexed[(int) $split->getCheckpoint()->getId()] = $split;
        }

        return $indexed;
    }
}
