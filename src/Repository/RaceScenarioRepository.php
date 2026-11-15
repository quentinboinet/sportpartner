<?php
namespace App\Repository;

use App\Entity\Race;
use App\Entity\RaceScenario;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RaceScenario>
 */
class RaceScenarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RaceScenario::class);
    }

    /** @return list<RaceScenario> */
    public function findByRaceOrdered(Race $race): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.race = :race')
            ->setParameter('race', $race)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByRace(Race $race): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.race = :race')
            ->setParameter('race', $race)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Position à attribuer à un nouveau scénario (max + 1, 0 si aucun). */
    public function nextPosition(Race $race): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->where('s.race = :race')
            ->setParameter('race', $race)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : (int) $max + 1;
    }
}
