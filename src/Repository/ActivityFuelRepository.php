<?php

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\ActivityFuel;
use App\Entity\User;
use App\Service\Nutrition\FuelItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityFuel>
 */
class ActivityFuelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityFuel::class);
    }

    /** @return list<ActivityFuel> */
    public function findByActivity(Activity $activity): array
    {
        return $this->findBy(['activity' => $activity], ['id' => 'ASC']);
    }

    /**
     * Dernière valeur de glucides enregistrée par l'utilisateur pour chaque clé demandée, toutes activités
     * confondues : celle de la ligne la plus récemment enregistrée (id le plus grand, les lignes étant
     * réécrites à chaque enregistrement). Sert de valeur proposée ("mon gel = 22 g" est retenu).
     *
     * @param list<string> $itemKeys
     *
     * @return array<string, float> itemKey => glucides
     */
    public function findLatestCarbsByItemKey(User $user, array $itemKeys): array
    {
        if ($itemKeys === [] || $user->getId() === null) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT f.itemKey, f.carbsPerUnit
               FROM activityFuel f
               INNER JOIN (
                   SELECT f2.itemKey AS itemKey, MAX(f2.id) AS maxId
                     FROM activityFuel f2
                     INNER JOIN activities a ON a.id = f2.activity_id
                    WHERE a.user_id = :userId AND f2.itemKey IN (:keys)
                    GROUP BY f2.itemKey
               ) latest ON latest.maxId = f.id',
            ['userId' => $user->getId(), 'keys' => $itemKeys],
            ['keys' => ArrayParameterType::STRING],
        );

        $carbs = [];
        foreach ($rows as $key => $value) {
            $carbs[(string) $key] = (float) $value;
        }

        return $carbs;
    }

    /**
     * Remplace toutes les lignes de l'activité par $items, dans une seule transaction.
     *
     * @param list<FuelItem> $items lignes à stocker (voir FuelItem::isStorable())
     */
    public function replaceForActivity(Activity $activity, array $items): void
    {
        $this->getEntityManager()->wrapInTransaction(static function (EntityManagerInterface $em) use ($activity, $items): void {
            // DELETE exécuté tout de suite : un remove() passerait APRÈS les INSERT du flush
            // et la contrainte unique (activity_id, itemKey) les bloquerait.
            $em->createQuery('DELETE FROM '.ActivityFuel::class.' f WHERE f.activity = :activity')
                ->setParameter('activity', $activity)
                ->execute();
            foreach ($items as $item) {
                $em->persist(ActivityFuel::fromItem($activity, $item));
            }
            // wrapInTransaction() fait le flush puis le commit
        });
    }
}
