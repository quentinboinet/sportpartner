<?php

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\Gear;
use App\Entity\GearType;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Gear>
 */
class GearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Gear::class);
    }

    /**
     * Tout le matériel de l'utilisateur (marque chargée), par défaut d'abord puis du plus récent au plus ancien.
     *
     * @return list<Gear>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->addSelect('b')
            ->join('g.brand', 'b')
            ->where('g.user = :user')
            ->setParameter('user', $user)
            ->orderBy('g.isDefault', 'DESC')
            ->addOrderBy('g.createdAt', 'DESC')
            ->addOrderBy('g.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Matériel importé de Strava (retiré compris), indexé par identifiant Strava ("b123", "g456").
     *
     * @return array<string, Gear>
     */
    public function findStravaGearMap(User $user): array
    {
        /** @var list<Gear> $rows */
        $rows = $this->createQueryBuilder('g')
            ->where('g.user = :user')
            ->andWhere('g.stravaGearId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $gear) {
            $map[(string) $gear->getStravaGearId()] = $gear;
        }

        return $map;
    }

    /**
     * Matériel en service (non retiré) dont l'usure est suivie (durée de vie renseignée), avec son
     * usage, en une seule requête agrégée (tableau de bord).
     *
     * @return list<array{gear: Gear, usage: array{activitiesCount: int, distanceM: float, movingSeconds: int, lastUsedAt: ?\DateTimeImmutable}}>
     */
    public function findActiveTrackedWithUsage(User $user): array
    {
        /** @var list<array{0: Gear, cnt: int|string, dist: float|int|string|null, secs: int|string|null, lastUsed: ?string}> $rows */
        $rows = $this->createQueryBuilder('g')
            ->addSelect('b')
            ->addSelect('COUNT(a.id) AS cnt')
            ->addSelect('COALESCE(SUM(a.distanceMeters), 0) AS dist')
            ->addSelect('COALESCE(SUM(a.movingTimeSeconds), 0) AS secs')
            ->addSelect('MAX(a.startDate) AS lastUsed')
            ->join('g.brand', 'b')
            ->leftJoin(Activity::class, 'a', Join::WITH, 'a.gear = g')
            ->where('g.user = :user')
            ->andWhere('g.retiredAt IS NULL')
            ->andWhere('g.maxDistanceM > 0')
            ->setParameter('user', $user)
            ->groupBy('g.id')
            ->addGroupBy('b.id')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'gear'  => $row[0],
                'usage' => [
                    'activitiesCount' => (int) $row['cnt'],
                    'distanceM'       => (float) $row['dist'],
                    'movingSeconds'   => (int) $row['secs'],
                    'lastUsedAt'      => $row['lastUsed'] !== null ? new \DateTimeImmutable($row['lastUsed']) : null,
                ],
            ];
        }

        return $result;
    }

    /**
     * Matériel par défaut (non retiré) de l'utilisateur, indexé par type ('shoes', 'bike').
     *
     * @return array<string, Gear>
     */
    public function findDefaultsByType(User $user): array
    {
        /** @var list<Gear> $rows */
        $rows = $this->createQueryBuilder('g')
            ->where('g.user = :user')
            ->andWhere('g.isDefault = true')
            ->andWhere('g.retiredAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $defaults = [];
        foreach ($rows as $gear) {
            $defaults[$gear->getType()->value] ??= $gear;
        }

        return $defaults;
    }

    /**
     * Types du matériel en service (non retiré) de l'utilisateur, en une requête.
     *
     * @return list<GearType>
     */
    public function findActiveTypes(User $user): array
    {
        $values = $this->createQueryBuilder('g')
            ->select('DISTINCT g.type AS type')
            ->where('g.user = :user')
            ->andWhere('g.retiredAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult();

        $types = [];
        foreach ($values as $value) {
            $type = $value instanceof GearType ? $value : GearType::tryFrom((string) $value);
            if ($type !== null && !in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** Retire le statut "par défaut" aux autres matériels du même utilisateur et du même type. */
    public function clearOtherDefaults(Gear $gear): void
    {
        $qb = $this->createQueryBuilder('g')
            ->update()
            ->set('g.isDefault', ':false')
            ->where('g.user = :user')
            ->andWhere('g.type = :type')
            ->setParameter('false', false)
            ->setParameter('user', $gear->getUser())
            ->setParameter('type', $gear->getType()->value);
        if ($gear->getId() !== null) {
            $qb->andWhere('g.id != :id')->setParameter('id', $gear->getId());
        }
        $qb->getQuery()->execute();
    }

    /**
     * Usage de chaque matériel de l'utilisateur, en une seule requête agrégée.
     *
     * @return array<int, array{activitiesCount: int, distanceM: float, movingSeconds: int, lastUsedAt: ?\DateTimeImmutable}>
     */
    public function getUsageByGear(User $user): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT g.id                                  AS gear_id,
                    COUNT(a.id)                           AS cnt,
                    COALESCE(SUM(a.distanceMeters), 0)    AS dist,
                    COALESCE(SUM(a.movingTimeSeconds), 0) AS secs,
                    MAX(a.startDate)                      AS last_used
             FROM gear g
             LEFT JOIN activities a ON a.gear_id = g.id
             WHERE g.user_id = :userId
             GROUP BY g.id',
            ['userId' => $user->getId()]
        );

        $usage = [];
        foreach ($rows as $row) {
            $usage[(int) $row['gear_id']] = [
                'activitiesCount' => (int) $row['cnt'],
                'distanceM'       => (float) $row['dist'],
                'movingSeconds'   => (int) $row['secs'],
                'lastUsedAt'      => $row['last_used'] !== null ? new \DateTimeImmutable((string) $row['last_used']) : null,
            ];
        }

        return $usage;
    }

    /**
     * Rattache le matériel aux activités de l'utilisateur sans matériel, des sports de son type
     * (et depuis sa date d'achat si elle est connue).
     *
     * @return int Nombre d'activités mises à jour.
     */
    public function assignToActivitiesWithoutGear(Gear $gear): int
    {
        $sql    = 'UPDATE activities a
                   JOIN sports s ON s.id = a.sport_id
                   SET a.gear_id = :gearId
                   WHERE a.user_id = :userId
                     AND a.gear_id IS NULL
                     AND s.slug IN (:slugs)';
        $params = [
            'gearId' => $gear->getId(),
            'userId' => $gear->getUser()->getId(),
            'slugs'  => $gear->getType()->sportSlugs(),
        ];
        $types  = ['slugs' => ArrayParameterType::STRING];

        if ($gear->getPurchasedAt() !== null) {
            $sql .= ' AND a.startDate >= :since';
            $params['since'] = $gear->getPurchasedAt()->format('Y-m-d 00:00:00');
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement($sql, $params, $types);
    }

    /**
     * Matériel proposé pour une activité : non retiré et d'un type compatible avec son sport,
     * plus le matériel déjà rattaché (même retiré).
     *
     * @param list<GearType> $types
     *
     * @return list<Gear>
     */
    public function findSelectable(User $user, array $types, ?Gear $current): array
    {
        $selectable = [];
        foreach ($this->findByUser($user) as $gear) {
            $isCurrent = $current !== null && $gear->getId() === $current->getId();
            if ($isCurrent || (!$gear->isRetired() && in_array($gear->getType(), $types, true))) {
                $selectable[] = $gear;
            }
        }

        return $selectable;
    }
}
