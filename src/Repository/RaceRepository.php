<?php
namespace App\Repository;

use App\Entity\Race;
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
    public function findByUser(User $user, ?int $year = null, ?string $intent = null): array
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

        if ($intent !== null && in_array($intent, ['want_to_do', 'bookmark'], true)) {
            $qb->andWhere('r.intent = :intent')->setParameter('intent', $intent);
        }

        return $qb->getQuery()->getResult();
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
