<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration de DONNÉES : le site d'une course (race.website) qui n'est pas une adresse http(s) passe à NULL.
 *
 * Le formulaire n'accepte que des liens http(s) (App\Service\Race\RaceInput), mais une valeur plus ancienne
 * (« javascript:... », « data:... », « ftp://... ») pourrait encore être rendue dans un href. Les templates ne la
 * rendent plus en lien (Race::getLinkableWebsite()), et cette migration la retire de la base.
 *
 * Motif : début « http:// » ou « https:// » sans tenir compte de la casse (LOWER), puis au moins un caractère et aucun
 * espace, comme RaceInput::isLinkable(). REGEXP et la classe [[:space:]] existent sous MySQL 8 et MariaDB.
 *
 * down() ne fait rien, volontairement : les valeurs effacées étaient dangereuses et ne sont pas conservées, il n'y a
 * rien à restaurer (revenir en arrière ne remet pas de site sur ces courses).
 */
final class Version20240033_CleanRaceWebsites extends AbstractMigration
{
    /** Motif des sites gardés (comparé à LOWER(website)). */
    public const KEEP_PATTERN = '^https?://[^[:space:]]+$';

    public function getDescription(): string { return 'Set race.website to NULL when it is not an http(s) URL (data migration, irreversible)'; }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE race SET website = NULL WHERE website IS NOT NULL AND LOWER(website) NOT REGEXP '".self::KEEP_PATTERN."'");
    }

    public function down(Schema $schema): void
    {
        // Rien à défaire : voir le docblock de la classe.
    }
}
