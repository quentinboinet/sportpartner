<?php

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\HealthSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HealthSession>
 */
class HealthSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HealthSession::class);
    }

    /**
     * Séances de l'utilisateur, la plus récente d'abord, exercices et activité chargés d'avance.
     *
     * @return list<HealthSession>
     */
    public function findPageForUser(User $user, int $page, int $perPage): array
    {
        $query = $this->createQueryBuilder('s')
            ->addSelect('x', 'a')
            ->leftJoin('s.exercises', 'x')
            ->leftJoin('s.activity', 'a')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.startedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->addOrderBy('x.position', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery();

        /** @var list<HealthSession> $sessions */
        $sessions = iterator_to_array(new Paginator($query, fetchJoinCollection: true), false);

        return $sessions;
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByActivity(Activity $activity): ?HealthSession
    {
        return $this->findOneBy(['activity' => $activity]);
    }

    /**
     * Parmi les activités données, celles créées par une séance Santé, en une requête (badge d'origine de la
     * liste des activités).
     *
     * @param list<int> $activityIds
     *
     * @return array<int, true> id d'activité => true
     */
    public function findActivityIdsAmong(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.activity) AS activityId')
            ->where('s.activity IN (:ids)')
            ->setParameter('ids', $activityIds)
            ->getQuery()
            ->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['activityId']] = true;
        }

        return $ids;
    }
}
