<?php
namespace App\Repository;

use App\Entity\Race;
use App\Entity\RaceShare;
use App\Service\Share\ShareToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RaceShare>
 */
class RaceShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RaceShare::class);
    }

    public function findOneByRace(Race $race): ?RaceShare
    {
        return $this->findOneBy(['race' => $race]);
    }

    /**
     * Partage ACTIF correspondant exactement au jeton, sinon null (jeton mal formé, inconnu, régénéré ou partage
     * désactivé : même réponse). Un jeton mal formé ne déclenche aucune requête. La colonne a une collation
     * insensible à la casse : la comparaison exacte est refaite ici (hash_equals).
     */
    public function findActiveByToken(string $token): ?RaceShare
    {
        if (!ShareToken::isValid($token)) {
            return null;
        }

        $share = $this->findOneBy(['token' => $token]);
        if (null === $share || !$share->isEnabled() || !hash_equals($share->getToken(), $token)) {
            return null;
        }

        return $share;
    }
}
