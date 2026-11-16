<?php
namespace App\Repository;

use App\Entity\Race;
use App\Entity\RaceIntent;
use App\Entity\RaceType;
use App\Entity\User;
use App\Service\Notifications\RaceTodoRow;
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
     * Prochaine course datée à laquelle l'utilisateur est inscrit (aujourd'hui inclus, non faite).
     */
    public function findNextRegistered(User $user): ?Race
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.intent = :intent')
            ->andWhere('r.raceDate >= :today')
            ->andWhere('r.isDone = false')
            ->setParameter('user', $user)
            ->setParameter('intent', RaceIntent::Registered->value)
            ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->orderBy('r.raceDate', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Courses « Je suis inscrit » non faites datées entre $from et $to inclus, avec l'état de leur plan, en une
     * seule requête et sans charger le tracé GPX (liste « À faire » de la cloche).
     *
     * @return list<RaceTodoRow>
     */
    public function findTodoRows(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{id: int|string, name: string, location: ?string, raceDate: \DateTimeImmutable, routes: int|string, checkpoints: int|string, scenarios: int|string, timedScenarios: int|string}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT r.id, r.name, r.location, r.raceDate,
                    (SELECT COUNT(rr.id) FROM App\Entity\RaceRoute rr WHERE rr.race = r) AS routes,
                    (SELECT COUNT(c.id) FROM App\Entity\RaceCheckpoint c WHERE c.race = r) AS checkpoints,
                    (SELECT COUNT(s.id) FROM App\Entity\RaceScenario s WHERE s.race = r) AS scenarios,
                    (SELECT COUNT(t.id) FROM App\Entity\RaceScenario t WHERE t.race = r AND t.targetSeconds > 0) AS timedScenarios
               FROM App\Entity\Race r
              WHERE r.user = :user
                AND r.intent = :intent
                AND r.isDone = false
                AND r.raceDate BETWEEN :from AND :to
              ORDER BY r.raceDate ASC, r.id ASC'
        )
            ->setParameter('user', $user)
            ->setParameter('intent', RaceIntent::Registered->value)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getArrayResult();

        return array_map(static fn (array $row): RaceTodoRow => new RaceTodoRow(
            id: (int) $row['id'],
            name: $row['name'],
            date: $row['raceDate'],
            location: $row['location'],
            hasRoute: (int) $row['routes'] > 0,
            checkpoints: (int) $row['checkpoints'],
            scenarios: (int) $row['scenarios'],
            timedScenarios: (int) $row['timedScenarios'],
        ), $rows);
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
