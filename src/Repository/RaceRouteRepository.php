<?php
namespace App\Repository;

use App\Entity\Race;
use App\Entity\RaceRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RaceRoute>
 */
class RaceRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RaceRoute::class);
    }

    public function findOneByRace(Race $race): ?RaceRoute
    {
        return $this->findOneBy(['race' => $race]);
    }
}
