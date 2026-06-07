<?php
namespace App\Repository;

use App\Entity\Meal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MealRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Meal::class);
    }

    public function findByUserAndDate(User $user, \DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->andWhere('m.mealDate = :date')
            ->setParameter('user', $user)
            ->setParameter('date', $date)
            ->orderBy('m.mealTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Returns array keyed by 'Y-m-d' => kcal total */
    public function getDailyIntake(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT mealDate as d,
                    COALESCE(SUM(kcal), SUM(carbsG * 4 + proteinsG * 4 + fatsG * 9)) as total_kcal
             FROM Meal
             WHERE user_id = :userId
               AND mealDate BETWEEN :from AND :to
             GROUP BY mealDate',
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['d']] = (int)$row['total_kcal'];
        }
        return $result;
    }
}
