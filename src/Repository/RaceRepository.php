<?php
namespace App\Repository;

use App\Entity\Race;
use App\Entity\RaceIntent;
use App\Entity\RaceType;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Race>
 */
class RaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Race::class);
    }

    /**
     * @return Race[]
     */
    public function findByUser(User $user, ?int $year = null, ?string $intent = null, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.year', 'ASC')
            ->addOrderBy('r.isDone', 'ASC')
            ->addOrderBy('r.name', 'ASC');

        if ($year !== null) {
            $qb->andWhere('r.year = :year')->setParameter('year', $year);
        }

        if ($intent !== null && RaceIntent::tryFrom($intent) !== null) {
            $qb->andWhere('r.intent = :intent')->setParameter('intent', $intent);
        }

        if ($type !== null && RaceType::tryFrom($type) !== null) {
            $qb->andWhere('r.type = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Courses datées dans l'intervalle [from, to[ (calendrier général).
     *
     * @return list<Race>
     */
    public function findDatedBetween(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.raceDate >= :from AND r.raceDate < :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('r.raceDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Prochaines courses datées (aujourd'hui inclus), non faites.
     *
     * @return list<Race>
     */
    public function findUpcoming(User $user, int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.raceDate >= :today')
            ->andWhere('r.isDone = false')
            ->setParameter('user', $user)
            ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->orderBy('r.raceDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int[]
     */
    public function getYearsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('DISTINCT r.year')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.year', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'year');
    }
}
