<?php

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    public function findByUserPaginated(User $user, int $page = 1, int $perPage = 20): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.startDate', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function findWeeklyVolume(User $user, int $weeks = 12): array
    {
        $since = new \DateTimeImmutable("-{$weeks} weeks");

        return $this->createQueryBuilder('a')
            ->select('YEAR(a.startDate) as year, WEEK(a.startDate) as week, SUM(a.distanceMeters) as totalDistance, SUM(a.movingTimeSeconds) as totalTime, SUM(a.totalElevationGain) as totalElevation, COUNT(a.id) as count')
            ->where('a.user = :user')
            ->andWhere('a.startDate >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->groupBy('year, week')
            ->orderBy('year', 'ASC')
            ->addOrderBy('week', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function findByStravaId(int $stravaId): ?Activity
    {
        return $this->findOneBy(['stravaId' => $stravaId]);
    }
}
