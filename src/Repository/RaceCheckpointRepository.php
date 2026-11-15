<?php
namespace App\Repository;

use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RaceCheckpoint>
 */
class RaceCheckpointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RaceCheckpoint::class);
    }

    /** @return list<RaceCheckpoint> */
    public function findByRaceOrdered(Race $race): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.race = :race')
            ->setParameter('race', $race)
            ->orderBy('c.distanceM', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
