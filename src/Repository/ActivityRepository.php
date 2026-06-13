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
        $conn  = $this->getEntityManager()->getConnection();

        // WEEK(..., 1) = mode ISO : semaine lundi→dimanche.
        // YEARWEEK(..., 1) pour le GROUP BY évite le bug de fin d'année
        // (ex: 30 déc. est semaine 1 de l'année suivante en ISO).
        return $conn->fetchAllAssociative(
            'SELECT YEARWEEK(startDate, 1)              AS yearweek,
                    WEEK(startDate, 1)                   AS week,
                    YEAR(DATE_ADD(startDate,
                        INTERVAL (8 - DAYOFWEEK(startDate)) % 7 DAY)) AS year,
                    COALESCE(SUM(distanceMeters), 0)     AS totalDistance,
                    COALESCE(SUM(movingTimeSeconds), 0)  AS totalTime,
                    COALESCE(SUM(totalElevationGain), 0) AS totalElevation,
                    COUNT(*)                             AS count
             FROM activities
             WHERE user_id = :userId
               AND startDate >= :since
             GROUP BY YEARWEEK(startDate, 1)
             ORDER BY YEARWEEK(startDate, 1) ASC',
            ['userId' => $user->getId(), 'since' => $since->format('Y-m-d')]
        );
    }

    public function findByUserFiltered(User $user, ?string $sportSlug = null, int $page = 1, int $perPage = 50, ?\DateTimeImmutable $since = null): array
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

        if ($since !== null) {
            $qb->andWhere('a.startDate >= :since')
               ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    public function getAggregateStats(User $user, ?string $sportSlug = null, ?\DateTimeImmutable $since = null): array
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

        if ($since !== null) {
            $qb->andWhere('a.startDate >= :since')
               ->setParameter('since', $since);
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

    public function findTodayStatsForSidebar(User $user): array
    {
        $conn = $this->getEntityManager()->getConnection();
        return $conn->fetchAllAssociative(
            'SELECT distanceMeters, movingTimeSeconds, averageHeartrate
             FROM activities
             WHERE user_id = :userId
               AND DATE(startDate) = :today',
            ['userId' => $user->getId(), 'today' => (new \DateTimeImmutable())->format('Y-m-d')]
        );
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

    public function getDailyVolumeBySport(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        return $conn->fetchAllAssociative(
            'SELECT DATE(a.startDate)                          AS day,
                    COALESCE(s.slug, \'other\')                AS sport,
                    COALESCE(s.color, \'#9CA3AF\')             AS color,
                    ROUND(SUM(a.distanceMeters) / 1000, 1)    AS km
             FROM activities a
             LEFT JOIN sports s ON a.sport_id = s.id
             WHERE a.user_id = :userId
               AND a.startDate BETWEEN :from AND :to
               AND a.distanceMeters IS NOT NULL
             GROUP BY day, sport, color
             ORDER BY day ASC',
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );
    }

    public function getWeeklyVolumeBySport(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        return $conn->fetchAllAssociative(
            'SELECT DATE_SUB(DATE(a.startDate), INTERVAL WEEKDAY(a.startDate) DAY) AS week_start,
                    COALESCE(s.slug, \'other\')                                     AS sport,
                    COALESCE(s.color, \'#9CA3AF\')                                  AS color,
                    ROUND(SUM(a.distanceMeters) / 1000, 1)                         AS km
             FROM activities a
             LEFT JOIN sports s ON a.sport_id = s.id
             WHERE a.user_id = :userId
               AND a.startDate BETWEEN :from AND :to
               AND a.distanceMeters IS NOT NULL
             GROUP BY week_start, sport, color
             ORDER BY week_start ASC',
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );
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
