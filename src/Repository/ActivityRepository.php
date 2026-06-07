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

    public function findByUserFiltered(User $user, ?string $sportSlug = null, int $page = 1, int $perPage = 50): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.startDate', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($sportSlug !== null) {
            $qb->join('a.sport', 's')
               ->andWhere('s.slug = :slug')
               ->setParameter('slug', $sportSlug);
        }

        return $qb->getQuery()->getResult();
    }

    public function getAggregateStats(User $user, ?string $sportSlug = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id) as count, SUM(a.distanceMeters) as totalDistance, SUM(a.movingTimeSeconds) as totalTime, SUM(a.totalElevationGain) as totalElevation')
            ->where('a.user = :user')
            ->setParameter('user', $user);

        if ($sportSlug !== null) {
            $qb->join('a.sport', 's')
               ->andWhere('s.slug = :slug')
               ->setParameter('slug', $sportSlug);
        }

        return $qb->getQuery()->getArrayResult()[0] ?? [];
    }

    public function findByMonthForCalendar(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('s')
            ->leftJoin('a.sport', 's')
            ->where('a.user = :user')
            ->andWhere('a.startDate BETWEEN :from AND :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByStravaId(int $stravaId): ?Activity
    {
        return $this->findOneBy(['stravaId' => $stravaId]);
    }

    /** Returns all already-imported Strava IDs for a user (for duplicate-check before sync). */
    public function findExistingStravaIds(User $user): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.stravaId')
            ->where('a.user = :user')
            ->andWhere('a.stravaId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        return array_map('intval', array_column($rows, 'stravaId'));
    }

    // ── Stats page queries ─────────────────────────────────────────────────

    public function getSeasonStats(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $row  = $conn->fetchAssociative(
            'SELECT COUNT(*) as cnt,
                    COALESCE(SUM(distanceMeters), 0)      as total_distance,
                    COALESCE(SUM(movingTimeSeconds), 0)   as total_time,
                    COALESCE(SUM(totalElevationGain), 0)  as total_elevation
             FROM activities
             WHERE user_id = :userId
               AND startDate BETWEEN :from AND :to',
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );
        return $row ?: ['cnt' => 0, 'total_distance' => 0, 'total_time' => 0, 'total_elevation' => 0];
    }

    public function getMonthlyVolumeBySport(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        return $conn->fetchAllAssociative(
            'SELECT YEAR(a.startDate)                          as yr,
                    MONTH(a.startDate)                         as mo,
                    COALESCE(s.slug, \'other\')                as sport,
                    COALESCE(s.color, \'#9CA3AF\')             as color,
                    ROUND(SUM(a.distanceMeters) / 1000, 1)    as km
             FROM activities a
             LEFT JOIN sports s ON a.sport_id = s.id
             WHERE a.user_id = :userId
               AND a.startDate BETWEEN :from AND :to
               AND a.distanceMeters IS NOT NULL
             GROUP BY yr, mo, sport, color
             ORDER BY yr ASC, mo ASC',
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );
    }

    public function getSportDistribution(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT COALESCE(s.slug, \'other\')             as slug,
                    COALESCE(s.color, \'#9CA3AF\')          as color,
                    ROUND(SUM(a.distanceMeters) / 1000, 1) as km
             FROM activities a
             LEFT JOIN sports s ON a.sport_id = s.id
             WHERE a.user_id = :userId
               AND a.startDate BETWEEN :from AND :to
               AND a.distanceMeters IS NOT NULL
             GROUP BY slug, color
             ORDER BY km DESC',
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );

        $total = array_sum(array_column($rows, 'km'));
        foreach ($rows as &$row) {
            $row['pct'] = $total > 0 ? (int) round((float) $row['km'] / $total * 100) : 0;
            $row['km']  = round((float) $row['km'], 1);
        }
        return $rows;
    }

    public function getWeeklyAvgPaceForSport(User $user, string $slug, \DateTimeImmutable $from): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT YEAR(a.startDate)                                              as yr,
                    WEEK(a.startDate, 1)                                           as wk,
                    ROUND(AVG(a.movingTimeSeconds / a.distanceMeters * 1000))     as avg_pace_sec
             FROM activities a
             JOIN sports s ON a.sport_id = s.id
             WHERE a.user_id    = :userId
               AND s.slug       = :slug
               AND a.startDate  >= :from
               AND a.distanceMeters  > 100
               AND a.movingTimeSeconds > 0
             GROUP BY yr, wk
             ORDER BY yr DESC, wk DESC
             LIMIT 12',
            ['userId' => $user->getId(), 'slug' => $slug, 'from' => $from->format('Y-m-d')]
        );
        return array_reverse($rows);
    }

    public function getWeightedHrStats(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $row  = $conn->fetchAssociative(
            'SELECT SUM(averageHeartrate * movingTimeSeconds) / NULLIF(SUM(movingTimeSeconds), 0) as weighted_avg_hr,
                    MAX(maxHeartrate) as max_hr
             FROM activities
             WHERE user_id        = :userId
               AND startDate      BETWEEN :from AND :to
               AND averageHeartrate IS NOT NULL',
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );
        return $row ?: ['weighted_avg_hr' => null, 'max_hr' => null];
    }

    public function getPersonalRecords(User $user): array
    {
        $conn    = $this->getEntityManager()->getConnection();
        $records = [];

        $distPRs = [
            ['label' => '5 km',          'slug' => 'run', 'min_m' => 4700,  'max_m' => 5300],
            ['label' => '10 km',         'slug' => 'run', 'min_m' => 9600,  'max_m' => 10400],
            ['label' => 'Semi-marathon', 'slug' => 'run', 'min_m' => 20500, 'max_m' => 21700],
        ];
        foreach ($distPRs as $pr) {
            $row = $conn->fetchAssociative(
                'SELECT a.movingTimeSeconds as val, a.startDate as start_date, s.slug, s.color
                 FROM activities a JOIN sports s ON a.sport_id = s.id
                 WHERE a.user_id = :userId AND s.slug = :slug
                   AND a.distanceMeters BETWEEN :minM AND :maxM
                   AND a.movingTimeSeconds > 0
                 ORDER BY a.movingTimeSeconds ASC
                 LIMIT 1',
                ['userId' => $user->getId(), 'slug' => $pr['slug'], 'minM' => $pr['min_m'], 'maxM' => $pr['max_m']]
            );
            if ($row) {
                $records[] = ['label' => $pr['label'], 'format' => 'time',
                              'slug' => $row['slug'], 'color' => $row['color'],
                              'val' => (int) $row['val'], 'start_date' => $row['start_date']];
            }
        }

        // Longest ride
        $row = $conn->fetchAssociative(
            'SELECT a.distanceMeters as val, a.startDate as start_date, s.slug, s.color
             FROM activities a JOIN sports s ON a.sport_id = s.id
             WHERE a.user_id = :userId AND s.slug = \'ride\' AND a.distanceMeters > 0
             ORDER BY a.distanceMeters DESC LIMIT 1',
            ['userId' => $user->getId()]
        );
        if ($row) {
            $records[] = ['label' => 'stats.record_longest_ride', 'format' => 'km',
                          'slug' => $row['slug'], 'color' => $row['color'],
                          'val' => round((float) $row['val'] / 1000, 1), 'start_date' => $row['start_date']];
        }

        // Highest elevation (trail / hike)
        $row = $conn->fetchAssociative(
            'SELECT a.totalElevationGain as val, a.startDate as start_date, s.slug, s.color
             FROM activities a JOIN sports s ON a.sport_id = s.id
             WHERE a.user_id = :userId AND s.slug IN (\'trail\', \'hike\')
               AND a.totalElevationGain > 0
             ORDER BY a.totalElevationGain DESC LIMIT 1',
            ['userId' => $user->getId()]
        );
        if ($row) {
            $records[] = ['label' => 'stats.record_max_elevation', 'format' => 'm',
                          'slug' => $row['slug'], 'color' => $row['color'],
                          'val' => (int) $row['val'], 'start_date' => $row['start_date']];
        }

        $sevenDaysAgo = (new \DateTimeImmutable())->modify('-7 days');
        foreach ($records as &$rec) {
            $rec['is_new'] = !empty($rec['start_date']) &&
                             new \DateTimeImmutable($rec['start_date']) > $sevenDaysAgo;
        }

        return $records;
    }

    public function findByUserAndDay(User $user, \DateTimeImmutable $date): array
    {
        $from = $date->setTime(0, 0, 0);
        $to   = $date->setTime(23, 59, 59);
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->andWhere('a.startDate BETWEEN :from AND :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    /** Returns array keyed by 'Y-m-d' => estimated kcal expenditure from activities */
    public function getDailyActivityKcal(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT DATE(startDate) as d, ROUND(SUM(movingTimeSeconds) / 60 * 8) as kcal_est
             FROM activities
             WHERE user_id = :userId
               AND startDate BETWEEN :from AND :to
             GROUP BY d',
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['d']] = (int)$row['kcal_est'];
        }
        return $result;
    }
}
