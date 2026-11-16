<?php

namespace App\Repository;

use App\Entity\HealthExerciseText;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HealthExerciseText>
 */
class HealthExerciseTextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HealthExerciseText::class);
    }
}
