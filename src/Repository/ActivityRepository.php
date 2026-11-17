<?php

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\HealthSession;
use App\Entity\User;
use App\Service\Activity\ActivityExportRow;
use App\Service\Notifications\RecentActivityRow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /** @return list<Activity> */
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

    /**
     * Volume par semaine ISO depuis $since (lundi 00:00:00 de la plus ancienne semaine, voir
     * WeeklyVolumeSeries::since()) ; seules les semaines avec des activités sont renvoyées,
     * WeeklyVolumeSeries::fill() complète les autres à zéro.
     *
     * @return list<array<string, mixed>>
     */
    public function findWeeklyVolume(User $user, \DateTimeImmutable $since): array
    {
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
            ['userId' => $user->getId(), 'since' => $since->format('Y-m-d H:i:s')]
        );
    }

    /** @return list<Activity> */
    public function findByUserFiltered(User $user, ?string $sportSlug = null, int $page = 1, int $perPage = 50, ?\DateTimeImmutable $since = null): array
    {
        // Matériel + marque chargés d'avance (pastille de la liste, sans requête par ligne)
        $qb = $this->createQueryBuilder('a')
            ->addSelect('g', 'gb')
            ->leftJoin('a.gear', 'g')
            ->leftJoin('g.brand', 'gb')
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

    /** @return array<string, mixed> */
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

    /** @return list<Activity> */
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

    /** @return list<array<string, mixed>> */
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

    /**
     * Activités de l'utilisateur d'un sport donné parties entre deux instants UTC inclus (doublons probables
     * d'une saisie manuelle, voir ActivityDuplicateRule), la plus proche du début d'abord.
     *
     * @return list<Activity>
     */
    public function findStartingBetween(User $user, string $sportSlug, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): array
    {
        $utc = new \DateTimeZone('UTC');

        return $this->createQueryBuilder('a')
            ->addSelect('s')
            ->join('a.sport', 's')
            ->where('a.user = :user')
            ->andWhere('s.slug = :slug')
            ->andWhere('a.startDate BETWEEN :from AND :to')
            ->setParameter('user', $user)
            ->setParameter('slug', $sportSlug)
            // startDate est stocké en heure UTC : bornes écrites en UTC
            ->setParameter('from', $fromUtc->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->setParameter('to', $toUtc->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->orderBy('a.startDate', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lignes de l'export CSV (ActivityCsvExporter), dans l'ordre chronologique, lues en flux : UNE requête avec
     * le sport, le matériel, sa marque et la séance Santé d'origine joints, sans les flux JSON. Bornes comme les
     * statistiques (getSeasonStats()) : startDate >= $from et <= $to, comparés à l'heure stockée.
     *
     * @return iterable<ActivityExportRow>
     */
    public function exportRows(User $user, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $sportSlug = null): iterable
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.startDate, a.timezone, a.name, a.distanceMeters, a.movingTimeSeconds, a.elapsedTimeSeconds,
                      a.totalElevationGain, a.averageSpeed, a.averageHeartrate, a.maxHeartrate, a.stravaId,
                      s.slug AS sportSlug, g.model AS gearModel, g.nickname AS gearNickname, gb.name AS gearBrand,
                      h.id AS healthId')
            ->leftJoin('a.sport', 's')
            ->leftJoin('a.gear', 'g')
            ->leftJoin('g.brand', 'gb')
            ->leftJoin(HealthSession::class, 'h', Join::WITH, 'h.activity = a')
            ->where('a.user = :user')
            ->andWhere('a.startDate IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('a.startDate', 'ASC')
            ->addOrderBy('a.id', 'ASC');
        if ($from !== null) {
            $qb->andWhere('a.startDate >= :from')->setParameter('from', $from->format('Y-m-d H:i:s'));
        }
        if ($to !== null) {
            $qb->andWhere('a.startDate <= :to')->setParameter('to', $to->format('Y-m-d H:i:s'));
        }
        if ($sportSlug !== null) {
            $qb->andWhere('s.slug = :slug')->setParameter('slug', $sportSlug);
        }

        $num = static fn (mixed $v): ?float => $v !== null ? (float) $v : null;
        $int = static fn (mixed $v): ?int => $v !== null ? (int) $v : null;
        $utc = new \DateTimeZone('UTC');

        /** @var array<string, mixed> $row */
        foreach ($qb->getQuery()->toIterable([], AbstractQuery::HYDRATE_ARRAY) as $row) {
            /** @var \DateTimeInterface $start */
            $start = $row['startDate'];
            yield new ActivityExportRow(
                startUtc: new \DateTimeImmutable($start->format('Y-m-d H:i:s'), $utc),
                timezone: $row['timezone'] !== null ? (string) $row['timezone'] : null,
                sportSlug: $row['sportSlug'] !== null ? (string) $row['sportSlug'] : null,
                name: $row['name'] !== null ? (string) $row['name'] : null,
                distanceMeters: $num($row['distanceMeters']),
                movingSeconds: $int($row['movingTimeSeconds']),
                elapsedSeconds: $int($row['elapsedTimeSeconds']),
                elevationMeters: $num($row['totalElevationGain']),
                averageSpeed: $num($row['averageSpeed']),
                hrAvg: $num($row['averageHeartrate']),
                hrMax: $num($row['maxHeartrate']),
                gearBrand: $row['gearBrand'] !== null ? (string) $row['gearBrand'] : null,
                gearModel: $row['gearModel'] !== null ? (string) $row['gearModel'] : null,
                gearNickname: $row['gearNickname'] !== null ? (string) $row['gearNickname'] : null,
                stravaId: $int($row['stravaId']),
                fromHealthSession: $row['healthId'] !== null,
            );
        }
    }

    public function findByStravaId(int $stravaId): ?Activity
    {
        return $this->findOneBy(['stravaId' => $stravaId]);
    }

    /**
     * Returns all already-imported Strava IDs for a user (for duplicate-check before sync).
     *
     * @return list<int>
     */
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

    /**
     * Activités de l'utilisateur réduites à ce qu'il faut pour rattacher le matériel importé de
     * Strava (sans charger les entités et leurs flux JSON).
     *
     * @return list<array{id: int, stravaId: ?int, gearId: ?int, distanceM: float}>
     */
    public function findGearLinkRows(User $user): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, stravaId, gear_id, distanceMeters FROM activities WHERE user_id = :userId',
            ['userId' => $user->getId()]
        );

        return array_map(static fn (array $row): array => [
            'id'        => (int) $row['id'],
            'stravaId'  => $row['stravaId'] !== null ? (int) $row['stravaId'] : null,
            'gearId'    => $row['gear_id'] !== null ? (int) $row['gear_id'] : null,
            'distanceM' => (float) ($row['distanceMeters'] ?? 0),
        ], $rows);
    }

    /**
     * Rattache le matériel aux activités données (de cet utilisateur uniquement).
     *
     * @param list<int> $activityIds
     *
     * @return int Nombre d'activités mises à jour.
     */
    public function assignGear(User $user, int $gearId, array $activityIds): int
    {
        $updated = 0;
        foreach (array_chunk($activityIds, 1000) as $chunk) {
            $updated += (int) $this->getEntityManager()->getConnection()->executeStatement(
                'UPDATE activities SET gear_id = :gearId WHERE user_id = :userId AND id IN (:ids)',
                ['gearId' => $gearId, 'userId' => $user->getId(), 'ids' => $chunk],
                ['ids' => ArrayParameterType::INTEGER]
            );
        }

        return $updated;
    }

    // ── Stats page queries ─────────────────────────────────────────────────

    /** @return array<string, mixed> */
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
            // Heure de fin gardée : $to = 23:59:59 du dernier jour (le jour de fin était exclu avec 'Y-m-d')
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d H:i:s')]
        );
        return $row ?: ['cnt' => 0, 'total_distance' => 0, 'total_time' => 0, 'total_elevation' => 0];
    }

    /** @return list<array<string, mixed>> */
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
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d H:i:s')]
        );
    }

    /** @return list<array<string, mixed>> */
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
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d H:i:s')]
        );
    }

    /** @return list<array<string, mixed>> */
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
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d H:i:s')]
        );
    }

    /** @return list<array<string, mixed>> */
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
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d H:i:s')]
        );

        $total = array_sum(array_column($rows, 'km'));
        foreach ($rows as &$row) {
            $row['pct'] = $total > 0 ? (int) round((float) $row['km'] / $total * 100) : 0;
            $row['km']  = round((float) $row['km'], 1);
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
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

    /** @return array<string, mixed> */
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
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d H:i:s')]
        );
        return $row ?: ['weighted_avg_hr' => null, 'max_hr' => null];
    }

    /** @return list<array<string, mixed>> */
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

    /** @return list<Activity> */
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

    /**
     * Returns array keyed by 'Y-m-d' => estimated kcal expenditure from activities.
     *
     * @return array<string, int>
     */
    public function getDailyActivityKcal(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT DATE(startDate) as d, ROUND(SUM(movingTimeSeconds) / 60 * 8) as kcal_est
             FROM activities
             WHERE user_id = :userId
               AND startDate BETWEEN :from AND :to
             GROUP BY d',
            // Jours inclus : $to compte jusqu'à 23:59:59 (sinon le dernier jour, le dimanche de la semaine, était exclu)
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d').' 00:00:00', 'to' => $to->format('Y-m-d').' 23:59:59']
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['d']] = (int)$row['kcal_est'];
        }
        return $result;
    }

    /**
     * Activités parties depuis $since, la plus récente d'abord, réduites à ce que regarde la liste « À faire »
     * de la cloche : sport, durées, matériel rattaché, ravitaillement noté, séance Santé d'origine. Une seule
     * requête, sans les flux JSON.
     *
     * @return list<RecentActivityRow>
     */
    public function findRecentTodoRows(User $user, \DateTimeImmutable $since, int $limit = 300): array
    {
        /** @var list<array{id: int|string, name: ?string, startDate: \DateTimeImmutable, movingTimeSeconds: int|string|null, elapsedTimeSeconds: int|string|null, gearId: int|string|null, sportSlug: ?string, fuelRows: int|string, healthRows: int|string}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT a.id, a.name, a.startDate, a.movingTimeSeconds, a.elapsedTimeSeconds,
                    IDENTITY(a.gear) AS gearId, s.slug AS sportSlug,
                    (SELECT COUNT(f.id) FROM App\Entity\ActivityFuel f WHERE f.activity = a) AS fuelRows,
                    (SELECT COUNT(h.id) FROM App\Entity\HealthSession h WHERE h.activity = a) AS healthRows
               FROM App\Entity\Activity a
               LEFT JOIN a.sport s
              WHERE a.user = :user
                AND a.startDate >= :since
              ORDER BY a.startDate DESC, a.id DESC'
        )
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->setMaxResults($limit)
            ->getArrayResult();

        return array_map(static fn (array $row): RecentActivityRow => new RecentActivityRow(
            id: (int) $row['id'],
            name: $row['name'],
            startDate: $row['startDate'],
            sportSlug: $row['sportSlug'],
            movingSeconds: $row['movingTimeSeconds'] !== null ? (int) $row['movingTimeSeconds'] : null,
            elapsedSeconds: $row['elapsedTimeSeconds'] !== null ? (int) $row['elapsedTimeSeconds'] : null,
            hasGear: $row['gearId'] !== null,
            hasFuel: (int) $row['fuelRows'] > 0,
            fromHealthSession: (int) $row['healthRows'] > 0,
        ), $rows);
    }

    /**
     * Totaux par sport des activités parties entre $from et $to inclus, en une requête (objectifs
     * par sport du tableau de bord). Clé : id du sport, 0 pour les activités sans sport.
     *
     * @return array<int, array{distanceM: float, sessions: int, movingSeconds: int, elevationM: float}>
     */
    public function sumBySportBetween(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT sport_id,
                    COUNT(*)                             AS sessions,
                    COALESCE(SUM(distanceMeters), 0)     AS distanceM,
                    COALESCE(SUM(movingTimeSeconds), 0)  AS movingSeconds,
                    COALESCE(SUM(totalElevationGain), 0) AS elevationM
             FROM activities
             WHERE user_id = :userId
               AND startDate BETWEEN :from AND :to
             GROUP BY sport_id',
            ['userId' => $user->getId(), 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) ($row['sport_id'] ?? 0)] = [
                'distanceM'     => (float) $row['distanceM'],
                'sessions'      => (int) $row['sessions'],
                'movingSeconds' => (int) $row['movingSeconds'],
                'elevationM'    => (float) $row['elevationM'],
            ];
        }

        return $totals;
    }

    /**
     * Totaux par jour et par sport des activités parties dans l'une des périodes données (bornes incluses),
     * en une requête : comparaison de périodes des statistiques (PeriodComparator). Sport = slug, "other"
     * pour les activités sans sport. Allure : activités de plus de 100 m avec un temps en mouvement, comme
     * getWeeklyAvgPaceForSport() ; FC : moyenne pondérée par le temps en mouvement des activités avec FC.
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges
     *
     * @return list<array{day: string, sport: string, sessions: int, distanceM: float, movingSeconds: int, elevationM: float, maxDistanceM: float, maxElevationM: float, hrWeighted: float, hrSeconds: int, paceDistanceM: float, paceSeconds: int}>
     */
    public function sumByDayAndSport(User $user, array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        $params = ['userId' => $user->getId()];
        $where = [];
        foreach ($ranges as $i => [$from, $to]) {
            $where[] = "(a.startDate BETWEEN :from{$i} AND :to{$i})";
            $params['from'.$i] = $from->format('Y-m-d H:i:s');
            $params['to'.$i] = $to->format('Y-m-d H:i:s');
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DATE(a.startDate)                              AS day,
                    COALESCE(s.slug, \'other\')                    AS sport,
                    COUNT(*)                                       AS sessions,
                    COALESCE(SUM(a.distanceMeters), 0)             AS distanceM,
                    COALESCE(SUM(a.movingTimeSeconds), 0)          AS movingSeconds,
                    COALESCE(SUM(a.totalElevationGain), 0)         AS elevationM,
                    COALESCE(MAX(a.distanceMeters), 0)             AS maxDistanceM,
                    COALESCE(MAX(a.totalElevationGain), 0)         AS maxElevationM,
                    COALESCE(SUM(CASE WHEN a.averageHeartrate IS NOT NULL AND a.movingTimeSeconds > 0
                                      THEN a.averageHeartrate * a.movingTimeSeconds END), 0) AS hrWeighted,
                    COALESCE(SUM(CASE WHEN a.averageHeartrate IS NOT NULL AND a.movingTimeSeconds > 0
                                      THEN a.movingTimeSeconds END), 0)                     AS hrSeconds,
                    COALESCE(SUM(CASE WHEN a.distanceMeters > 100 AND a.movingTimeSeconds > 0
                                      THEN a.distanceMeters END), 0)                        AS paceDistanceM,
                    COALESCE(SUM(CASE WHEN a.distanceMeters > 100 AND a.movingTimeSeconds > 0
                                      THEN a.movingTimeSeconds END), 0)                     AS paceSeconds
             FROM activities a
             LEFT JOIN sports s ON a.sport_id = s.id
             WHERE a.user_id = :userId
               AND ('.implode(' OR ', $where).')
             GROUP BY day, sport
             ORDER BY day ASC, sport ASC',
            $params
        );

        return array_map(static fn (array $row): array => [
            'day'           => (string) $row['day'],
            'sport'         => (string) $row['sport'],
            'sessions'      => (int) $row['sessions'],
            'distanceM'     => (float) $row['distanceM'],
            'movingSeconds' => (int) $row['movingSeconds'],
            'elevationM'    => (float) $row['elevationM'],
            'maxDistanceM'  => (float) $row['maxDistanceM'],
            'maxElevationM' => (float) $row['maxElevationM'],
            'hrWeighted'    => (float) $row['hrWeighted'],
            'hrSeconds'     => (int) $row['hrSeconds'],
            'paceDistanceM' => (float) $row['paceDistanceM'],
            'paceSeconds'   => (int) $row['paceSeconds'],
        ], $rows);
    }
}
